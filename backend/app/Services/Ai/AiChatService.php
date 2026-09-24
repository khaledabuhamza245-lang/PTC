<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiChatService
{
    protected string $geminiKey;
    protected string $openRouterKey;

    public function __construct()
    {
        // سحب مفتاح جوجل ومفتاح أوبن روتر من ملف البيئة .env
        $this->geminiKey = env('GEMINI_API_KEY', '');
        $this->openRouterKey = env('OPENROUTER_API_KEY', '');
    }

    /**
     * Stream AI Response: Try Gemini Flash first, fallback to OpenRouter if needed
     */
    public function streamChatResponse(array $messages, ?string $fileUri = null, callable $onBuffer): void
    {
        try {
            // 1. المحاولة الأولى والأساسية: Gemini 1.5 Flash (مباشر وسريع)
            $this->streamFromGemini($messages, $fileUri, $onBuffer);
        } catch (\Exception $e) {
            Log::warning('Gemini API failed, falling back to OpenRouter.', [
                'error' => $e->getMessage()
            ]);

            // 2. شبكة الأمان (Fallback): OpenRouter Free Model
            if (!empty($this->openRouterKey)) {
                $this->streamFromOpenRouter($messages, $onBuffer);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Stream response from Google Gemini Flash API
     */
    protected function streamFromGemini(array $messages, ?string $fileUri, callable $onBuffer): void
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:streamGenerateContent?alt=sse&key={$this->geminiKey}";

        $contents = $this->formatMessagesForGemini($messages, $fileUri);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($url, [
            'contents' => $contents
        ]);

        if ($response->failed()) {
            throw new \Exception('Gemini API Error: ' . $response->body());
        }

        // قراءة البث الحقيقي للرد
        $body = $response->body();
        $lines = explode("\n", $body);

        foreach ($lines as $line) {
            if (str_starts_with($line, 'data: ')) {
                $jsonData = json_decode(substr($line, 6), true);
                $text = $jsonData['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($text !== '') {
                    $onBuffer($text);
                }
            }
        }
    }

    /**
     * Fallback Stream from OpenRouter
     */
    protected function streamFromOpenRouter(array $messages, callable $onBuffer): void
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->openRouterKey,
            'Content-Type' => 'application/json',
            'X-Title' => 'PTC Hub Assistant',
        ])->post('https://openrouter.ai/api/v1/chat/completions', [
            'model' => 'openrouter/free',
            'messages' => $messages,
            'stream' => true,
        ]);

        if ($response->failed()) {
            throw new \Exception('OpenRouter Fallback Error: ' . $response->body());
        }

        $lines = explode("\n", $response->body());
        foreach ($lines as $line) {
            if (str_starts_with($line, 'data: ') && $line !== 'data: [DONE]') {
                $jsonData = json_decode(substr($line, 6), true);
                $text = $jsonData['choices'][0]['delta']['content'] ?? '';
                if ($text !== '') {
                    $onBuffer($text);
                }
            }
        }
    }

    /**
     * Convert standard message format to Gemini format
     */
    protected function formatMessagesForGemini(array $messages, ?string $fileUri): array
    {
        $contents = [];
        $systemText = '';

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemText .= $msg['content'] . "\n";
                continue;
            }

            $parts = [];
            if ($msg['role'] === 'user' && $fileUri) {
                $parts[] = [
                    'fileData' => [
                        'mimeType' => 'application/pdf',
                        'fileUri' => $fileUri
                    ]
                ];
            }

            $parts[] = ['text' => $msg['content']];

            $contents[] = [
                'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => $parts
            ];
        }

        if (!empty($systemText) && count($contents) > 0) {
            array_unshift($contents[0]['parts'], ['text' => "Instructions: " . $systemText]);
        }

        return $contents;
    }
}