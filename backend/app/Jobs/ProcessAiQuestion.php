<?php

namespace App\Jobs;

use App\Models\AiQuestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class ProcessAiQuestion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 55;

    public function __construct(private readonly int $questionId)
    {
    }

    public function handle(): void
    {
        $question = AiQuestion::find($this->questionId);

        if (!$question) {
            return;
        }

        $apiKey = config('services.gemini.key');

        if (!$apiKey) {
            $question->update([
                'status' => 'failed',
                'error_message' => 'المساعد غير مفعّل حاليًا على الخادم.',
            ]);
            return;
        }

        $systemInstruction = 'أنت مساعد مذاكرة لطلاب هندسة أنظمة الحاسوب بكلية فلسطين التقنية. '
            . 'أجب بالعربية الفصحى المبسّطة ما لم يطلب المستخدم غير ذلك، بإيجاز ووضوح، '
            . 'وركّز على شرح المفاهيم البرمجية والهندسية بأمثلة عملية عند الإمكان. '
            . 'لا تجب عن أسئلة خارج نطاق الدراسة الهندسية أو التقنية.'
            . ($question->course_name ? ' الطالب حاليًا يستعرض مادة: ' . $question->course_name . '.' : '');

        $model = 'gemini-3.6-flash';

        try {
            $response = Http::timeout(45)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
                    [
                        'systemInstruction' => [
                            'parts' => [['text' => $systemInstruction]],
                        ],
                        'contents' => [
                            ['role' => 'user', 'parts' => [['text' => $question->message]]],
                        ],
                        'generationConfig' => [
                            'maxOutputTokens' => 3000,
                            'temperature' => 0.6,
                        ],
                    ]
                );
        } catch (\Throwable $e) {
            \Log::error('Gemini job failed (exception): ' . $e->getMessage());
            $question->update([
                'status' => 'failed',
                'error_message' => 'تعذّر الوصول للمساعد حاليًا، حاول لاحقًا.',
            ]);
            return;
        }

        if (!$response->successful()) {
            \Log::error('Gemini job failed (status ' . $response->status() . '): ' . $response->body());
            $question->update([
                'status' => 'failed',
                'error_message' => 'تعذّر الوصول للمساعد حاليًا، حاول لاحقًا.',
            ]);
            return;
        }

        $parts = $response->json('candidates.0.content.parts') ?? [];
        $text = collect($parts)->pluck('text')->filter()->implode('');

        if (!$text) {
            \Log::error('Gemini job returned no text. Full response: ' . $response->body());
            $question->update([
                'status' => 'failed',
                'error_message' => 'ما قدر المساعد يجاوب على هذا السؤال، جرّب صياغة مختلفة.',
            ]);
            return;
        }

        $question->update([
            'status' => 'done',
            'reply' => $text,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $question = AiQuestion::find($this->questionId);
        $question?->update([
            'status' => 'failed',
            'error_message' => 'تعذّر الوصول للمساعد حاليًا، حاول لاحقًا.',
        ]);
    }
}
