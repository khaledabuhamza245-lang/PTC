<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseFile;
use App\Models\CourseSection;
use App\Models\CourseUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseStructureController extends Controller
{
    /**
     * جلب بنية المادة:
     * المادة ← القسم ← الوحدة ← التصنيف ← المحتوى.
     */
    public function sections(Course $course)
    {
        $sections = CourseSection::query()
            ->where('course_id', $course->id)
            ->whereNull('course_unit_id')
            ->where(function ($query) {
                $query->whereNull('slug')
                    ->orWhere('slug', '!=', '__unit_container__');
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $units = CourseUnit::query()
            ->where('course_id', $course->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $categories = CourseSection::query()
            ->where('course_id', $course->id)
            ->whereNotNull('course_unit_id')
            ->where(function ($query) {
                $query->whereNull('slug')
                    ->orWhere('slug', '!=', '__unit_container__');
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('course_unit_id');

        $units->each(function (CourseUnit $unit) use ($categories) {
            $unit->setRelation(
                'categories',
                $categories->get($unit->id, collect())->values()
            );
        });

        $sectionIds = $sections
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $sections->each(function (CourseSection $section) use ($units) {
            $section->setRelation(
                'units',
                $units
                    ->filter(
                        fn (CourseUnit $unit) =>
                            (int) $unit->course_section_id ===
                            (int) $section->id
                    )
                    ->values()
            );
        });

        $orphanUnits = $units
            ->filter(function (CourseUnit $unit) use ($sectionIds) {
                if (! $unit->course_section_id) {
                    return true;
                }

                return ! $sectionIds->contains(
                    (int) $unit->course_section_id
                );
            })
            ->values();

        /*
         * المحتوى نفسه — وبه تصل الشجرة إلى ما يَعِد به تعليق الدالة.
         *
         * كانت تقف عند التصنيف، فيرى الطاقم البنية ولا يرى ما بداخلها،
         * ويضطر إلى جدول منفصل أسفل الصفحة لتعديل رابط يعرف موضعه في
         * الشجرة تمامًا. والجدول مُرقَّم فقد لا يكون العنصر في صفحته.
         *
         * والتجميع بـ course_section_id وحده يكفي للحالتين: التصنيف
         * والقسم الرئيسي كلاهما صفٌّ في course_sections، فالمحتوى
         * المعلَّق مباشرة تحت قسم يجد قسمه بنفس المفتاح.
         */
        $files = CourseFile::query()
            ->where('course_id', $course->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id', 'course_section_id', 'course_unit_id', 'title',
                'kind', 'external_url', 'is_published',
                'counts_toward_progress', 'sort_order',
            ])
            ->groupBy('course_section_id');

        $attachFiles = function ($node) use ($files) {
            $node->setRelation(
                'files',
                $files->get($node->id, collect())->values()
            );
        };

        $sections->each($attachFiles);
        $units->each(fn (CourseUnit $unit) => $unit->categories->each($attachFiles));

        return response()->json([
            'data' => [
                'sections' => $sections,
                'orphan_units' => $orphanUnits,

                // توافق مؤقت مع الواجهة القديمة.
                'units' => $units,
                'general_sections' => $sections,
            ],
        ]);
    }

    /**
     * إضافة قسم رئيسي للمادة، أو تصنيف داخل وحدة.
     */
    public function storeSection(
        Request $request,
        Course $course
    ) {
        $data = $request->validate([
            'course_unit_id' => [
                'nullable',
                'integer',
                'exists:course_units,id',
            ],
            'title' => [
                'required',
                'string',
                'max:190',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'icon' => [
                'nullable',
                'string',
                'max:50',
            ],
            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'counts_toward_progress' => [
                'nullable',
                'boolean',
            ],
            'is_published' => [
                'nullable',
                'boolean',
            ],
        ]);

        $unitId = $data['course_unit_id'] ?? null;

        if ($unitId) {
            $belongsToCourse = CourseUnit::query()
                ->whereKey($unitId)
                ->where('course_id', $course->id)
                ->exists();

            if (! $belongsToCourse) {
                throw ValidationException::withMessages([
                    'course_unit_id' => [
                        'الوحدة لا تتبع المادة المحددة.',
                    ],
                ]);
            }
        }

        if (! array_key_exists('sort_order', $data)) {
            $data['sort_order'] =
                ((int) CourseSection::query()
                    ->where('course_id', $course->id)
                    ->where('course_unit_id', $unitId)
                    ->max('sort_order')) + 1;
        }

        $section = new CourseSection();

        $section->forceFill([
            'course_id' => $course->id,
            'course_unit_id' => $unitId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'],
            'counts_toward_progress' =>
                $data['counts_toward_progress'] ?? false,
            'is_published' =>
                $data['is_published'] ?? true,
        ]);

        $section->save();

        return response()->json([
            'message' => $unitId
                ? 'تمت إضافة التصنيف داخل الوحدة.'
                : 'تمت إضافة القسم الرئيسي.',
            'data' => $section->fresh(),
        ], 201);
    }

    /**
     * تعديل قسم رئيسي أو تصنيف.
     */
    public function updateSection(
        Request $request,
        CourseSection $section
    ) {
        $data = $request->validate([
            'title' => [
                'sometimes',
                'required',
                'string',
                'max:190',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'icon' => [
                'nullable',
                'string',
                'max:50',
            ],
            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
            ],
            'counts_toward_progress' => [
                'sometimes',
                'boolean',
            ],
            'is_published' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $section->update($data);

        return response()->json([
            'message' => $section->course_unit_id
                ? 'تم تحديث التصنيف.'
                : 'تم تحديث القسم.',
            'data' => $section->fresh(),
        ]);
    }

    /**
     * حذف قسم رئيسي أو تصنيف ومحتواه.
     */
    public function deleteSection(CourseSection $section)
    {
        DB::transaction(function () use ($section) {
            /*
             * السجل تصنيف داخل وحدة.
             */
            if ($section->course_unit_id) {
                CourseFile::query()
                    ->where(
                        'course_section_id',
                        $section->id
                    )
                    ->delete();

                $section->delete();

                return;
            }

            /*
             * السجل قسم رئيسي.
             */
            $unitIds = CourseUnit::query()
                ->where(
                    'course_id',
                    $section->course_id
                )
                ->where(
                    'course_section_id',
                    $section->id
                )
                ->pluck('id');

            if ($unitIds->isNotEmpty()) {
                $categoryIds = CourseSection::query()
                    ->whereIn(
                        'course_unit_id',
                        $unitIds
                    )
                    ->pluck('id');

                if ($categoryIds->isNotEmpty()) {
                    CourseFile::query()
                        ->whereIn(
                            'course_section_id',
                            $categoryIds
                        )
                        ->delete();

                    CourseSection::query()
                        ->whereIn(
                            'id',
                            $categoryIds
                        )
                        ->delete();
                }

                CourseFile::query()
                    ->whereIn(
                        'course_unit_id',
                        $unitIds
                    )
                    ->delete();

                CourseUnit::query()
                    ->whereIn('id', $unitIds)
                    ->delete();
            }

            /*
             * تنظيف أي محتوى قديم مرتبط
             * بالقسم مباشرة.
             */
            CourseFile::query()
                ->where(
                    'course_section_id',
                    $section->id
                )
                ->delete();

            $section->delete();
        });

        return response()->json([
            'message' =>
                'تم حذف القسم ووحداته وتصنيفاته ومحتواه.',
        ]);
    }

    /**
     * إضافة وحدة داخل قسم رئيسي
     * مع التصنيفات الافتراضية.
     */
    public function storeUnit(
        Request $request,
        Course $course
    ) {
        $data = $request->validate([
            'course_section_id' => [
                'required',
                'integer',
                'exists:course_sections,id',
            ],
            'title' => [
                'required',
                'string',
                'max:190',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'is_published' => [
                'nullable',
                'boolean',
            ],
            'create_default_categories' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $parentSection = CourseSection::query()
            ->whereKey(
                $data['course_section_id']
            )
            ->where(
                'course_id',
                $course->id
            )
            ->whereNull('course_unit_id')
            ->first();

        if (! $parentSection) {
            throw ValidationException::withMessages([
                'course_section_id' => [
                    'القسم المحدد لا يتبع المادة أو ليس قسمًا رئيسيًا.',
                ],
            ]);
        }

        /*
         * كانت مثبَّتة على false، والقاعدة أعلاه تتحقق من مفتاح لا يقرأه
         * أحد. فالواجهة ترسل true دائمًا، والخادم يردّ «وتصنيفاتها
         * الأساسية»، واللوحة تكرّر الادعاء — والوحدة تُنشأ بلا تصنيف واحد.
         * ثم يعجز الطاقم عن رفع أي ملف إليها لأن الرفع يستلزم تصنيفًا،
         * فيبحث عن عطل في الرفع والعطل في رسالة كاذبة.
         */
        $createDefaults = (bool) (
            $data['create_default_categories'] ?? false
        );

        if (! array_key_exists('sort_order', $data)) {
            $data['sort_order'] =
                ((int) CourseUnit::query()
                    ->where(
                        'course_id',
                        $course->id
                    )
                    ->where(
                        'course_section_id',
                        $parentSection->id
                    )
                    ->max('sort_order')) + 1;
        }

        $unit = DB::transaction(
            function () use (
                $course,
                $parentSection,
                $data,
                $createDefaults
            ) {
                $unit = new CourseUnit();

                $unit->forceFill([
                    'course_id' => $course->id,
                    'course_section_id' =>
                        $parentSection->id,
                    'title' => $data['title'],
                    'description' =>
                        $data['description'] ?? null,
                    'sort_order' =>
                        $data['sort_order'],
                    'is_published' =>
                        $data['is_published'] ?? true,
                ]);

                $unit->save();

                if ($createDefaults) {
                    $defaults = [
                        [
                            'title' =>
                                'المحاضرات وملفاتها',
                            'sort_order' => 1,
                            'counts_toward_progress' =>
                                true,
                        ],
                        [
                            'title' =>
                                'التعيينات والواجبات',
                            'sort_order' => 2,
                            'counts_toward_progress' =>
                                true,
                        ],
                        [
                            'title' =>
                                'التدريبات والأسئلة',
                            'sort_order' => 3,
                            'counts_toward_progress' =>
                                true,
                        ],
                        [
                            'title' => 'روابط مهمة',
                            'sort_order' => 4,
                            'counts_toward_progress' =>
                                false,
                        ],
                    ];

                    foreach (
                        $defaults as $categoryData
                    ) {
                        $category =
                            new CourseSection();

                        $category->forceFill([
                            'course_id' =>
                                $course->id,
                            'course_unit_id' =>
                                $unit->id,
                            'title' =>
                                $categoryData[
                                    'title'
                                ],
                            'description' => null,
                            'icon' => null,
                            'sort_order' =>
                                $categoryData[
                                    'sort_order'
                                ],
                            'counts_toward_progress' =>
                                $categoryData[
                                    'counts_toward_progress'
                                ],
                            'is_published' => true,
                        ]);

                        $category->save();
                    }
                }

                $unit->setRelation(
                    'categories',
                    CourseSection::query()
                        ->where(
                            'course_id',
                            $course->id
                        )
                        ->where(
                            'course_unit_id',
                            $unit->id
                        )
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->get()
                );

                return $unit;
            }
        );

        // الرسالة تتبع ما جرى فعلًا لا ما طُلب.
        return response()->json([
            'message' => $createDefaults
                ? 'تمت إضافة الوحدة داخل القسم وتصنيفاتها الأساسية.'
                : 'تمت إضافة الوحدة داخل القسم.',
            'data' => $unit,
        ], 201);
    }

    /**
     * إضافة وحدة من مسار القسم.
     */
    public function storeUnitFromSection(
        Request $request,
        CourseSection $section
    ) {
        if ($section->course_unit_id) {
            throw ValidationException::withMessages([
                'section' => [
                    'لا يمكن إضافة وحدة داخل تصنيف.',
                ],
            ]);
        }

        $course = Course::query()
            ->findOrFail(
                $section->course_id
            );

        $request->merge([
            'course_section_id' =>
                $section->id,
        ]);

        return $this->storeUnit(
            $request,
            $course
        );
    }

    /**
     * تعديل الوحدة أو نقلها إلى قسم آخر.
     */
    public function updateUnit(
        Request $request,
        CourseUnit $unit
    ) {
        $data = $request->validate([
            'course_section_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:course_sections,id',
            ],
            'title' => [
                'sometimes',
                'required',
                'string',
                'max:190',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
            ],
            'is_published' => [
                'sometimes',
                'boolean',
            ],
        ]);

        if (
            array_key_exists(
                'course_section_id',
                $data
            )
        ) {
            $parentSection = CourseSection::query()
                ->whereKey(
                    $data['course_section_id']
                )
                ->where(
                    'course_id',
                    $unit->course_id
                )
                ->whereNull('course_unit_id')
                ->first();

            if (! $parentSection) {
                throw ValidationException::withMessages([
                    'course_section_id' => [
                        'القسم المحدد لا يتبع المادة أو ليس قسمًا رئيسيًا.',
                    ],
                ]);
            }

            $isMoving =
                (int) $unit->course_section_id !==
                (int) $parentSection->id;

            if (
                $isMoving &&
                ! array_key_exists(
                    'sort_order',
                    $data
                )
            ) {
                $data['sort_order'] =
                    ((int) CourseUnit::query()
                        ->where(
                            'course_id',
                            $unit->course_id
                        )
                        ->where(
                            'course_section_id',
                            $parentSection->id
                        )
                        ->max('sort_order')) + 1;
            }
        }

        $unit->update($data);

        $freshUnit = $unit->fresh();

        $freshUnit->setRelation(
            'categories',
            CourseSection::query()
                ->where(
                    'course_id',
                    $freshUnit->course_id
                )
                ->where(
                    'course_unit_id',
                    $freshUnit->id
                )
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );

        return response()->json([
            'message' => 'تم تحديث الوحدة.',
            'data' => $freshUnit,
        ]);
    }

    /**
     * حذف الوحدة وتصنيفاتها ومحتواها.
     */
    public function deleteUnit(CourseUnit $unit)
    {
        DB::transaction(function () use ($unit) {
            $categoryIds = CourseSection::query()
                ->where(
                    'course_id',
                    $unit->course_id
                )
                ->where(
                    'course_unit_id',
                    $unit->id
                )
                ->pluck('id');

            if ($categoryIds->isNotEmpty()) {
                CourseFile::query()
                    ->whereIn(
                        'course_section_id',
                        $categoryIds
                    )
                    ->delete();

                CourseSection::query()
                    ->whereIn(
                        'id',
                        $categoryIds
                    )
                    ->delete();
            }

            CourseFile::query()
                ->where(
                    'course_unit_id',
                    $unit->id
                )
                ->delete();

            $unit->delete();
        });

        return response()->json([
            'message' =>
                'تم حذف الوحدة وتصنيفاتها ومحتواها.',
        ]);
    }
}