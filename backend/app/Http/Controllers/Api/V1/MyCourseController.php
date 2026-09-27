<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CourseStatusException;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\MyCourseStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * طبقة رقيقة فقط: تترجم بين HTTP request/response وبين قواعد العمل
 * الفعلية الموجودة الآن بمكان واحد وحيد — App\Services\MyCourseStatusService.
 *
 * (خطوة ١٠٢، بعد خطوة ١٠١ التي أدخلت ميزة التحكم بالخطة من بوت
 * تيليجرام): كانت هذه القواعد قبل هذه الخطوة مكرَّرة حرفيًا بمكانين —
 * هذا المتحكّم، ونسخة موازية بـMyCourseStatusService كُتبت خصّيصًا
 * للبوت لتفادي لمس هذا الكود الحي وقتها بلا إشراف مباشر من المستخدم.
 * بعد إثبات استقرار الخدمة حيًّا عبر البوت، تم توحيدهما هنا: هذا
 * المتحكّم الآن يستدعي الخدمة بدل تكرار جسمها، فلا يبقى مكانان يمكن
 * أن يختلفا بقاعدة عمل أو رسالة خطأ لاحقًا.
 */
class MyCourseController extends Controller
{
    public function __construct(
        private readonly MyCourseStatusService $courseStatusService,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json([
            'data' => $request
                ->user()
                ->myCourses()
                ->orderBy('semester')
                ->get(),
        ]);
    }

    /**
     * إضافة مساق يدويًا إلى مساقات الطالب الحالية.
     */
    public function store(Request $request, Course $course)
    {
        try {
            $this->courseStatusService->attach($request->user(), $course);
        } catch (CourseStatusException $e) {
            if ($e->status === 404) {
                // نفس abort_unless($course->is_active, 404) السابقة حرفيًا:
                // يمر عبر معالج bootstrap/app.php العام الذي يستبدل أي
                // رسالة 404 بالنص العربي الموحّد، فلا نمرّر رسالة هنا.
                abort(404);
            }

            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'message' => 'تمت إضافة المساق.',
        ], 201);
    }

    /**
     * تحديث حالة المساق.
     */
    public function update(Request $request, Course $course)
    {
        $user = $request->user();

        $existing = DB::table('my_courses')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        // الترتيب مقصود ومطابق للسابق: 404 قبل التحقق من صحة الحقول،
        // حتى لا يسبق خطأ تحقّق (422) عدمَ وجود تسجيل أصلًا (404).
        abort_unless($existing, 404);

        $data = $request->validate([
            'status' => [
                'sometimes',
                Rule::in([
                    'registered',
                    'completed',
                    'dropped',
                ]),
            ],

            'term_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('terms', 'id'),
            ],

            'grade' => [
                'sometimes',
                'nullable',
                'string',
                'max:5',
            ],
        ]);

        $result = $this->courseStatusService->updateFields($user, $course, $data);

        return response()->json([
            'message' => 'تم تحديث حالة المساق.',
            'data' => $result,
        ]);
    }

    /**
     * حذف مساق.
     *
     * مساق الفصل الحالي لا نحذفه نهائيًا من DB،
     * لأن المزامنة ستعيد إضافته.
     *
     * نسجل أن الطالب استبعده يدويًا.
     */
    public function destroy(Request $request, Course $course)
    {
        $this->courseStatusService->remove($request->user(), $course);

        return response()->json([
            'message' => 'تم حذف المساق.',
        ]);
    }
}