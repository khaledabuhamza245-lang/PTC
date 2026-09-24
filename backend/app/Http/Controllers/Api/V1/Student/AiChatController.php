<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\CourseFileAiMeta;
use App\Services\Ai\AiChatService;
use App\Services\Ai\StudentContextBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AiChatController extends Controller
{
    protected AiChatService $aiService;

    public function __construct(AiChatService $aiService)
    {
        $this->aiService = $aiService;
    }

    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:1000',
            'course_file_id' => 'nullable|exists:course_files,id',
            'history' => 'nullable|array'
        ]);

        $user = $request->user();
        
        // 1. تحميل علاقة myCourses المسجلة في موديل User
        if (method_exists($user, 'myCourses')) {
            $user->load('myCourses');
        }

        $today = now()->toDateString();

        // 2. التحقق من الحصة اليومية (حد 20 سؤالاً يومياً)
        $usage = AiUsage::firstOrCreate(
            ['user_id' => $user->id, 'usage_date' => $today],
            ['request_count' => 0]
        );

        if ($usage->request_count >= 20) {
            return response()->json([
                'status' => 'error',
                'message' => 'لقد استهلكت حصتك اليومية المتاحة (20 سؤالاً يومياً). يرجى العودة غداً! 🚀'
            ], 429);
        }
        
// إلغاء الكاش مؤقتاً لقراءة البيانات المحدثة دائماً
$cacheKey = 'ai_chat_' . $user->id . '_' . md5($request->message . '_' . ($request->course_file_id ?? 'none'));
Cache::forget($cacheKey);

        // زيادة عداد الاستهلاك عند إنشاء استجابة جديدة
        $usage->increment('request_count');

        // 4. التحقق من وجود ملف PDF مرتبط بالسؤال
        $fileUri = null;
        if ($request->course_file_id) {
            $meta = CourseFileAiMeta::where('course_file_id', $request->course_file_id)->first();
            $fileUri = $meta?->google_file_uri;
        }

        // 5. بناء الـ System Context وتجهيز الرسائل
        $systemPrompt = StudentContextBuilder::build($user);
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        if ($request->history && is_array($request->history)) {
            foreach ($request->history as $hist) {
                if (isset($hist['role'], $hist['content'])) {
                    $messages[] = ['role' => $hist['role'], 'content' => $hist['content']];
                }
            }
        }

        $messages[] = ['role' => 'user', 'content' => $request->message];

        // 6. إرجاع البث الحي وحفظ الإجابة الكاملة في الكاش
        return response()->stream(function () use ($messages, $fileUri, $cacheKey) {
            $fullResponse = '';

            $this->aiService->streamChatResponse($messages, $fileUri, function ($chunk) use (&$fullResponse) {
                $fullResponse .= $chunk;
                echo "data: " . json_encode(['text' => $chunk]) . "\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            });

            if (!empty($fullResponse)) {
                Cache::put($cacheKey, $fullResponse, now()->addHours(24));
            }

            echo "data: [DONE]\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }
}