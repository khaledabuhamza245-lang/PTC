<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseContentProgress;
use App\Models\CourseFile;
use Illuminate\Http\Request;

class CourseStructureController extends Controller
{
    public function show(Request $request, Course $course)
    {
        abort_unless($course->is_active, 404);

        $user = $request->user('sanctum');

        $isCourseStudent = $user
            ? $user->myCourses()
                ->whereKey($course->id)
                ->exists()
            : false;

        $completedIds = $user
            ? CourseContentProgress::query()
                ->where('user_id', $user->id)
                ->whereNotNull('completed_at')
                ->whereHas(
                    'courseFile',
                    fn ($query) =>
                        $query->where(
                            'course_id',
                            $course->id
                        )
                )
                ->pluck('course_file_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        // المصدر الوحيد لقاعدة الظهور — لا يُعاد تركيبها هنا.
        $canView = fn (CourseFile $file): bool =>
            $file->isVisibleTo(
                $user,
                $isCourseStudent
            );

        /*
         * الوحدات وتصنيفات كل وحدة.
         */
        $units = $course->units()
    ->where('is_published', true)
    ->with([
        'categories' => fn ($query) =>
            $query
                ->where('is_published', true)
                ->orderBy('sort_order')
                ->orderBy('id'),

        'categories.contents' => fn ($query) =>
            $query
                ->orderBy('sort_order')
                ->orderBy('id'),
    ])
    ->orderBy('sort_order')
    ->orderBy('id')
    ->get()
    ->map(function ($unit) use ($canView) {
        $categories = $unit->categories
            ->map(function ($category) use ($canView) {
                $contents = $category
                    ->contents
                    ->filter($canView)
                    ->values();

                return [
                    'id' => $category->id,
                    'title' => $category->title,
                    'description' =>
                        $category->description,
                    'icon' => $category->icon,
                    'sort_order' =>
                        $category->sort_order,
                    'counts_toward_progress' =>
                        $category
                            ->counts_toward_progress,
                    'contents' => $contents,
                ];
            })
            ->values();

        return [
            'id' => $unit->id,
            'course_section_id' =>
                $unit->course_section_id,
            'title' => $unit->title,
            'description' => $unit->description,
            'sort_order' => $unit->sort_order,
            'categories' => $categories,
        ];
    })
    ->values();
            

        /*
         * الأقسام الرئيسية.
         */
        $sectionModels = $course->sections()
            ->whereNull('course_unit_id')
            ->where('is_published', true)
            ->with([
                'contents' => fn ($query) =>
                    $query
                        ->whereNull('course_unit_id')
                        ->orderBy('sort_order')
                        ->orderBy('id'),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $publishedSectionIds = $sectionModels
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        /*
         * وحدةٌ تحت قسم **موجود لكن غير منشور** تسقط من الحمولة كلها.
         *
         * بلا هذا كانت تُصنَّف يتيمة — لأن أبيها ليس في المنشورة — فتُرفع
         * إلى قسم «محتوى إضافي» الاصطناعي ويراها الطالب بتصنيفاتها وملفاتها
         * كاملة. أي أن إخفاء القسم كان **يُظهر** ما تحته لا يخفيه، وهو عكس
         * ما يقصده من ضغط الزر تمامًا.
         *
         * والتمييز ضروري: اليتيمة الحقيقية هي التي لا أب لها أصلًا (بنية
         * قديمة سابقة لقلب الهيكل)، وتلك تبقى ظاهرة وإلا اختفى محتوى قديم
         * بلا أثر. فالإسقاط مقصور على من له أب معروف مُخفى عمدًا.
         */
        $hiddenSectionIds = $course->sections()
            ->whereNull('course_unit_id')
            ->where('is_published', false)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $units = $units
            ->reject(
                fn (array $unit) =>
                    $hiddenSectionIds->contains(
                        (int) ($unit['course_section_id'] ?? 0)
                    )
            )
            ->values();

        $sections = $sectionModels
            ->map(function ($section) use (
                $units,
                $canView
            ) {
                $sectionUnits = $units
                    ->filter(
                        fn (array $unit) =>
                            (int) $unit[
                                'course_section_id'
                            ] === (int) $section->id
                    )
                    ->values();

                $directContents = $section
                    ->contents
                    ->filter($canView)
                    ->values();

                return [
                    'id' => $section->id,
                    'title' => $section->title,
                    'description' =>
                        $section->description,
                    'icon' => $section->icon,
                    'sort_order' =>
                        $section->sort_order,
                    'counts_toward_progress' =>
                        $section
                            ->counts_toward_progress,
                    'units' => $sectionUnits,
                    'contents' => $directContents,
                ];
            })
            
            ->values();

        /*
         * وحدات قديمة غير مرتبطة بقسم منشور.
         */
        $orphanUnits = $units
            ->filter(function (array $unit) use (
                $publishedSectionIds
            ) {
                $parentId = (int) (
                    $unit['course_section_id'] ?? 0
                );

                return ! $parentId
                    || ! $publishedSectionIds
                        ->contains($parentId);
            })
            ->values();

        /*
         * محتوى قديم غير مرتبط بقسم.
         */
        $orphanContents = $course->files()
            ->whereNull('course_section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter($canView)
            ->values();

        if (
            $orphanUnits->isNotEmpty()
            || $orphanContents->isNotEmpty()
        ) {
            $sections->push([
                'id' => 'unclassified',
                'title' => 'محتوى إضافي',
                'description' => null,
                'icon' => 'paperclip',
                'sort_order' => 999999,
                'counts_toward_progress' => false,
                'units' => $orphanUnits,
                'contents' => $orphanContents,
            ]);
        }

        /*
         * حساب تقدّم الطالب من كل المحتوى.
         */
        $allContents = $sections->flatMap(
            function (array $section) {
                $unitContents = collect(
                    $section['units']
                )->flatMap(
                    fn (array $unit) =>
                        collect(
                            $unit['categories']
                        )->flatMap(
                            fn (array $category) =>
                                $category['contents']
                        )
                );

                return $unitContents->concat(
                    $section['contents']
                );
            }
        );

        $trackableIds = $allContents
            ->filter(
                fn ($item) =>
                    (bool) data_get(
                        $item,
                        'counts_toward_progress'
                    )
            )
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $done = $trackableIds
            ->intersect($completedIds)
            ->count();

        $total = $trackableIds->count();

        /*
         * دليل أدوات المادة. يُرسل مع البنية لا بطلب منفصل: القسم
         * يظهر في الصفحة نفسها، فطلب ثانٍ يعني ومضة فراغ تحت الملفات.
         */
        $tools = $course->tools()
            ->where('is_active', true)
            ->get()
            ->map(fn ($tool) => [
                'id' => $tool->id,
                'name' => $tool->name,
                'type' => $tool->type,
                'description' => $tool->description,
                'official_url' => $tool->official_url,
                'video_url' => $tool->video_url,
                'explanation' => $tool->explanation,
            ])
            ->values();

        return response()->json([
    'data' => [
        'course' => $course,
        'tools' => $tools,
        'sections' => $sections,
        'units' => $units,
        'general_sections' => $sections,
        'completed_content_ids' => $completedIds,
        'progress' => [
            'completed' => $done,
            'total' => $total,
            'percentage' => $total
                ? (int) round($done * 100 / $total)
                : 0,
        ],
    ],
]);
    }

    public function setCompleted(
        Request $request,
        CourseFile $courseFile
    ) {
        $data = $request->validate([
            'completed' => [
                'required',
                'boolean',
            ],
        ]);

        $user = $request->user();

        $isCourseStudent = $user
            ->myCourses()
            ->whereKey($courseFile->course_id)
            ->exists();

        abort_unless(
            $courseFile->status === 'ready'
            && $courseFile->is_published
            && $courseFile->canBeViewedBy(
                $user,
                $isCourseStudent
            ),
            403
        );

        $progress =
            CourseContentProgress::firstOrCreate([
                'user_id' => $user->id,
                'course_file_id' =>
                    $courseFile->id,
            ]);

        $progress->update([
            'completed_at' =>
                $data['completed']
                    ? now()
                    : null,
        ]);

        return response()->json([
            'message' => 'تم تحديث الإنجاز.',
            'data' => [
                'course_file_id' =>
                    $courseFile->id,
                'completed' =>
                    (bool) $data['completed'],
            ],
        ]);
    }
}