<?php
 
namespace App\Services;
 
use Illuminate\Support\Facades\Http;
use RuntimeException;
 
class GeminiFileService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com';
 
    /*
     * رفع مباشر من السيرفر (لا من المتصفح) بطلب واحد فقط — يتجاوز
     * قيد CORS تمامًا لأن جوجل ترفض استقبال رفع الملفات من متصفح
     * مباشرة أساسًا؛ هذا اتصال سيرفر-لسيرفر لا علاقة له بتلك السياسة.
     */
    public function uploadFile(string $filePath, string $displayName, string $mimeType): array
    {
        $apiKey = config('services.gemini.key');
        if (!$apiKey) {
            throw new RuntimeException('Gemini غير مفعّل حاليًا على الخادم.');
        }
 
        $metadata = json_encode(['file' => ['display_name' => $displayName]]);
 
        $response = Http::timeout(120)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->attach('metadata', $metadata, 'metadata.json', ['Content-Type' => 'application/json'])
            ->attach('file', fopen($filePath, 'r'), $displayName, ['Content-Type' => $mimeType])
            ->post(self::BASE_URL . '/upload/v1beta/files?uploadType=multipart');
 
        if (!$response->successful()) {
            \Log::error('Gemini direct upload failed: ' . $response->body());
            throw new RuntimeException('تعذّر رفع الملف إلى المساعد.');
        }
 
        $file = $response->json('file') ?? [];
 
        if (empty($file['name']) || empty($file['uri'])) {
            throw new RuntimeException('لم يُرجع Gemini بيانات ملف صالحة.');
        }
 
        /*
         * الرفع لا يعني الجهوزية الفورية — جوجل قد تحتاج ثوانٍ إضافية
         * لتجهيز الملف (خصوصًا PDF متعدد الصفحات) قبل أن يصلح لاستخدامه
         * فعليًا بسؤال. الاستخدام المبكر قبل هذا كان سبب التعليق الطويل
         * ثم فشل الرد — الانتظار هنا (لا لاحقًا أثناء الإجابة) يحل هذا
         * جذريًا: يفشل الرفع بوضوح فورًا إن تعذّر التجهيز، بدل أن يعلَّق
         * طلب الإجابة نفسه لاحقًا بلا تفسير واضح للطالب.
         */
        return $this->getFile($file['name']);
    }
 
    public function startUpload(string $displayName, string $mimeType, int $size): string
    {
        $apiKey = config('services.gemini.key');
        if (!$apiKey) {
            throw new RuntimeException('Gemini غير مفعّل حاليًا على الخادم.');
        }
 
        $response = Http::timeout(20)
            ->withHeaders([
                'x-goog-api-key' => $apiKey,
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => (string) $size,
                'X-Goog-Upload-Header-Content-Type' => $mimeType,
                'Content-Type' => 'application/json',
            ])
            ->post(self::BASE_URL . '/upload/v1beta/files', [
                'file' => [
                    'display_name' => $displayName,
                ],
            ]);
 
        if (!$response->successful()) {
            throw new RuntimeException('تعذّر تجهيز رفع الملف إلى Gemini.');
        }
 
        $uploadUrl = $response->header('x-goog-upload-url');
        if (!$uploadUrl) {
            throw new RuntimeException('لم يُرجع Gemini رابط رفع صالحًا.');
        }
 
        return $uploadUrl;
    }
 
    public function getFile(string $name, int $waitSeconds = 25): array
    {
        $apiKey = config('services.gemini.key');
        if (!$apiKey) {
            throw new RuntimeException('Gemini غير مفعّل حاليًا على الخادم.');
        }
 
        $name = ltrim($name, '/');
        if (!str_starts_with($name, 'files/')) {
            throw new RuntimeException('معرّف ملف Gemini غير صالح.');
        }
 
        $deadline = microtime(true) + max(0, $waitSeconds);
        do {
            $response = Http::timeout(15)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->get(self::BASE_URL . '/v1beta/' . $name);
 
            if (!$response->successful()) {
                $body = $response->json();
                $providerMessage = data_get($body, 'error.message');
                throw new RuntimeException($providerMessage ?: 'تعذّر التحقق من ملف Gemini.');
            }
 
            $file = $response->json('file') ?? $response->json();
            $state = strtoupper((string) ($file['state'] ?? 'ACTIVE'));
 
            if ($state === 'ACTIVE' || $state === '') {
                return $file;
            }
 
            if ($state === 'FAILED') {
                $providerMessage = data_get($file, 'error.message');
                throw new RuntimeException($providerMessage ?: 'فشل Gemini في معالجة الملف.');
            }
 
            usleep(500000);
        } while (microtime(true) < $deadline);
 
        throw new RuntimeException('الملف ما زال قيد المعالجة لدى Gemini. حاول إرساله بعد لحظات.');
    }
 
    public function deleteFile(string $name): void
    {
        $apiKey = config('services.gemini.key');
        if (!$apiKey) return;
 
        $name = ltrim($name, '/');
        if (!str_starts_with($name, 'files/')) return;
 
        Http::timeout(15)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->delete(self::BASE_URL . '/v1beta/' . $name);
    }
}
 
