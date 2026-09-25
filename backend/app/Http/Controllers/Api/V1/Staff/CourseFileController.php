<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\CourseFile;
use App\Models\CourseSection;
use App\Models\CourseUnit;
use App\Services\TelegramContentNotifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourseFileController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', CourseFile::class);

        $items = CourseFile::query()
            ->with(['course:id,key,code,name_ar', 'section:id,title', 'unit:id,title'])
            ->when($request->integer('course_id'), fn ($query, $id) => $query->where('course_id', $id))
            ->orderBy('course_id')
            ->orderBy('course_unit_id')
            ->orderBy('course_section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(max(1, min($request->integer('per_page', 50), 100)));

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $this->authorize('create', CourseFile::class);

        $data = $this->validatedData($request, false);
        $data['created_by'] = $request->user()->id;
        $data['status'] = 'ready';
        $data['visibility'] ??= 'public';
        $data['counts_toward_progress'] ??= false;
        $data['is_published'] ??= true;
        $data['ai_summarizable'] ??= self::defaultAiSummarizable($data['kind'] ?? null);
        $data['storage_disk'] = null;
        $data['storage_path'] = null;
        $data['sort_order'] ??= $this->nextSortOrder($data);

        $file = CourseFile::create($data);

        /*
         * تنبيه تيليجرام للطلاب المسجّلين — معزول عمدًا بـtry/catch: أي
         * خلل بالبوت (توكن غير مضبوط، مشكلة اتصال بتيليجرام...) ما
         * يجوز إطلاقًا يمنع الطاقم من حفظ المحتوى نفسه، وهو الفعل
         * الأساسي هون. راجع توثيق TelegramContentNotifier للتفاصيل
         * (تنفيذ متزامن مؤقت، بانتظار طابور حقيقي).
         */
        try {
            app(TelegramContentNotifier::class)->notifyNewFile($file);
        } catch (\Throwable $error) {
            report($error);
        }

        return response()->json([
            'message' => 'تم حفظ المحتوى.',
            'data' => $file->load(['course:id,key,code,name_ar', 'section:id,title', 'unit:id,title']),
        ], 201);
    }

    public function update(Request $request, CourseFile $courseFile)
    {
        $this->authorize('update', $courseFile);

        $data = $this->validatedData($request, true, $courseFile);

        if (array_key_exists('course_section_id', $data)
            && (int) $data['course_section_id'] !== (int) $courseFile->course_section_id
            && ! array_key_exists('course_unit_id', $data)) {
            $data['course_unit_id'] = null;
        }

        $courseFile->update($data);

        return response()->json([
            'message' => 'تم تحديث المحتوى.',
            'data' => $courseFile->fresh()->load(['course:id,key,code,name_ar', 'section:id,title', 'unit:id,title']),
        ]);
    }

    public function destroy(CourseFile $courseFile)
    {
        $this->authorize('delete', $courseFile);

        $courseFile->delete();

        return response()->json(['message' => 'تم حذف المحتوى.']);
    }

    private function validatedData(Request $request, bool $partial, ?CourseFile $file = null): array
    {
        $rules = [
            'course_id' => [$partial ? 'sometimes' : 'required', 'integer', 'exists:courses,id'],
            'course_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:course_units,id'],
            'course_section_id' => [$partial ? 'sometimes' : 'required', 'integer', 'exists:course_sections,id'],
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'kind' => [$partial ? 'sometimes' : 'required', Rule::in([
                'pdf', 'doc', 'vid', 'youtube', 'drive', 'assignment', 'exercise',
                'exam', 'book', 'software', 'github', 'link', 'image', 'other',
            ])],
            /*
             * https حصرًا لا http: الموقع يُخدَّم على https، والمتصفح
             * يحجب أي إطار لرابط http بصمت (mixed content) — بلا خطأ
             * ولا حدث، فتظهر المعاينة إطارًا أبيض بلا سبب ظاهر.
             * المنع عند الإدخال أرخص من تشخيصه بعد النشر.
             */
            'external_url' => [$partial ? 'sometimes' : 'required', 'url:https', 'max:1000'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'counts_toward_progress' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            /*
             * تحكّم صريح للطاقم بإمكانية تلخيص/تحويل الملف لبطاقات مراجعة
             * بالمساعد الذكي — مطلوب لأنه أحيانًا نوع الملف نفسه (مثلًا
             * drive) قد يكون فعليًا برنامجًا للتحميل أو أرشيفًا لا مستندًا
             * قابلًا للقراءة، فتصنيف "kind" وحده غير كافٍ لضمان هذا دائمًا.
             * القيمة الافتراضية (حين لا يُرسَل الحقل) تُشتق من kind بدالة
             * defaultAiSummarizable أدناه فقط عند الإنشاء — التعديل لا
             * يعيد اشتقاقها أبدًا، فاختيار الطاقم السابق يبقى كما هو ما
             * لم يغيّره صراحة.
             */
            'ai_summarizable' => ['sometimes', 'boolean'],
            'visibility' => ['sometimes', Rule::in(['public', 'authenticated', 'course_students', 'staff_only'])],
        ];

        /*
         * رسالة صريحة: لوحة الأدمن ترسل external_url في كل حفظ حتى لو
         * لم يُعدَّل، فأي صف قديم رابطه http يرفض التعديل مهما كان
         * الحقل المقصود. الرسالة الافتراضية «رابط غير صالح» تُضلّل هنا،
         * فيلزم أن تقول ما العطل وما العلاج.
         */
        $data = $request->validate($rules, [
            'external_url.url' => 'الرابط يجب أن يبدأ بـ https:// — روابط http لا تُعرض داخل الموقع.',
        ]);
        $courseId = (int) ($data['course_id'] ?? $file?->course_id);
        $sectionId = array_key_exists('course_section_id', $data)
            ? ($data['course_section_id'] ? (int) $data['course_section_id'] : null)
            : ($file?->course_section_id ? (int) $file->course_section_id : null);
        $unitId = array_key_exists('course_unit_id', $data)
            ? ($data['course_unit_id'] ? (int) $data['course_unit_id'] : null)
            : ($file?->course_unit_id ? (int) $file->course_unit_id : null);

        if (! $sectionId || ! CourseSection::query()->whereKey($sectionId)->where('course_id', $courseId)->exists()) {
            throw ValidationException::withMessages([
                'course_section_id' => 'التصنيف لا يتبع المادة المحددة.',
            ]);
        }

        $section = CourseSection::query()->find($sectionId);

        if ($unitId) {
            $unit = CourseUnit::query()->find($unitId);
            if (! $unit || (int) $unit->course_id !== $courseId) {
                throw ValidationException::withMessages([
                    'course_unit_id' => 'الوحدة لا تتبع المادة المحددة.',
                ]);
            }
            if ((int) $section->course_unit_id !== $unitId) {
                throw ValidationException::withMessages([
                    'course_section_id' => 'التصنيف لا يتبع الوحدة المحددة.',
                ]);
            }
        } elseif ($section->course_unit_id !== null) {
            throw ValidationException::withMessages([
                'course_unit_id' => 'اختر الوحدة التابعة لهذا التصنيف.',
            ]);
        }

        return $data;
    }

    /*
     * افتراض ذكي فقط عند الإنشاء (لو ما حدّد الطاقم شيئًا صراحة) —
     * نفس تصنيف "kind" المستخدَم أصلًا بالواجهة (course-page.js) قبل
     * إضافة هذا الحقل، فسلوك الملفات الجديدة يبقى كما توقّعه الطاقم
     * تمامًا ما لم يعدّل مربّع الاختيار بنفسه بالفورم.
     */
    private static function defaultAiSummarizable(?string $kind): bool
    {
        return !in_array($kind, ['vid', 'youtube', 'link', 'github', 'software', 'image'], true);
    }

    private function nextSortOrder(array $data): int
    {
        return ((int) CourseFile::query()
            ->where('course_id', $data['course_id'])
            ->where('course_section_id', $data['course_section_id'] ?? null)
            ->where('course_unit_id', $data['course_unit_id'] ?? null)
            ->max('sort_order')) + 1;
    }
}