<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use Illuminate\Http\Request;

class ContentReportController extends Controller
{
    public function index(Request $request)
    {
        $query = ContentReport::query()
            ->with([
                'course:id,key,code,name_ar',
                'courseFile:id,course_id,title',
                'user:id,first_name,father_name,last_name,email',
            ])
            ->whereNull('resolved_at')
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

        $items->getCollection()->transform(function ($report) {
            $report->reason_label = ContentReport::REASONS[$report->reason] ?? $report->reason;

            return $report;
        });

        return response()->json($items);
    }

    /*
     * حلّ البلاغ = إقرار بمعالجته (عدّل الملف أو تجاهله لأنه غير
     * صحيح) — لا يحذف البلاغ نفسه، فيبقى سجلًّا لما حدث.
     */
    public function resolve(ContentReport $contentReport)
    {
        $contentReport->update([
            'resolved_at' => now(),
        ]);

        return response()->json([
            'message' => 'تم تمييز البلاغ كمُعالَج.',
        ]);
    }

    public function unreadCount()
    {
        $count = ContentReport::query()
            ->whereNull('reviewed_at')
            ->count();

        return response()->json([
            'data' => ['count' => $count],
        ]);
    }

    public function recent()
    {
        $items = ContentReport::query()
            ->with([
                'course:id,key,code,name_ar',
                'courseFile:id,course_id,title',
                'user:id,first_name,father_name,last_name',
            ])
            ->whereNull('resolved_at')
            ->latest()
            ->limit(15)
            ->get()
            ->map(function ($report) {
                $report->reason_label = ContentReport::REASONS[$report->reason] ?? $report->reason;

                return $report;
            });

        return response()->json([
            'data' => $items,
        ]);
    }

    public function markSeen()
    {
        ContentReport::query()
            ->whereNull('reviewed_at')
            ->update(['reviewed_at' => now()]);

        return response()->json([
            'message' => 'تم تحديث حالة الإشعارات.',
        ]);
    }
}
