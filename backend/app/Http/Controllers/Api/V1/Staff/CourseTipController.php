<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\CourseTip;
use Illuminate\Http\Request;

class CourseTipController extends Controller
{
    /*
     * على عكس الواجهة الطلابية، هون تظهر هوية كاتب النصيحة صراحةً —
     * المراجعة والمساءلة هما بالضبط سبب وجود هذه اللوحة.
     */
    public function index(Request $request)
    {
        $query = CourseTip::query()
            ->with([
                'course:id,key,code,name_ar',
                'user:id,first_name,father_name,last_name,email',
            ])
            ->latest();

        if ($courseKey = $request->string('course')->toString()) {
            $query->whereHas(
                'course',
                fn ($inner) => $inner->where('key', $courseKey)
            );
        }

        $items = $query->paginate(
            max(1, min($request->integer('per_page', 25), 100))
        );

        return response()->json($items);
    }

    public function destroy(CourseTip $courseTip)
    {
        $courseTip->delete();

        return response()->json([
            'message' => 'تم حذف النصيحة.',
        ]);
    }

    /*
     * عدّاد الإشعارات: كم نصيحة نُشرت ولم يرَها أي موظف بعد. هذا الطلب
     * وحده يتكرر كثيرًا (كل تحميل صفحة رئيسية للأدمن)، فيبقى خفيفًا —
     * عدّ فقط، بلا تحميل أي علاقات.
     */
    public function unreadCount()
    {
        $count = CourseTip::query()
            ->whereNull('reviewed_at')
            ->count();

        return response()->json([
            'data' => ['count' => $count],
        ]);
    }

    /*
     * آخر النصائح لعرضها بقائمة الجرس المنسدلة — بصرف النظر عن حالة
     * المراجعة، حتى يبقى للأدمن سجل قريب حتى بعد تصفير العدّاد.
     */
    public function recent()
    {
        $items = CourseTip::query()
            ->with([
                'course:id,key,code,name_ar',
                'user:id,first_name,father_name,last_name',
            ])
            ->latest()
            ->limit(15)
            ->get();

        return response()->json([
            'data' => $items,
        ]);
    }

    /*
     * فتح قائمة الجرس يُعلِّم كل النصائح المعروضة فيها كمُراجَعة —
     * تصفير العدّاد لا يعني حذفًا ولا موافقة، فقط «انتُبه إليها».
     */
    public function markSeen()
    {
        CourseTip::query()
            ->whereNull('reviewed_at')
            ->update(['reviewed_at' => now()]);

        return response()->json([
            'message' => 'تم تحديث حالة الإشعارات.',
        ]);
    }
}
