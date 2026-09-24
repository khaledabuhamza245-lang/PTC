<?php

namespace App\Jobs;

use App\Models\CourseFile;
use App\Models\CourseFileAiMeta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessCourseFileForAi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public CourseFile $file;

    public function __construct(CourseFile $file)
    {
        $this->file = $file;
    }

    public function handle(): void
    {
        $apiKey = env('GEMINI_API_KEY');

        if (!$apiKey || !$this->file->file_url) {
            return;
        }

        try {
            // 1. تحميل الملف مؤقتاً للتجهيز
            $fileContent = file_get_contents($this->file->file_url);
            $tempPath = storage_path("app/temp_ai_{$this->file->id}.pdf");
            file_put_contents($tempPath, $fileContent);

            $mimeType = 'application/pdf';
            $numBytes = filesize($tempPath);

            // 2. بدء عملية الرفع السحابي لـ Google AI Studio
            $response = Http::withHeaders([
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => $numBytes,
                'X-Goog-Upload-Header-Content-Type' => $mimeType,
                'Content-Type' => 'application/json',
            ])->post("https://generativelanguage.googleapis.com/v1beta/files?key={$apiKey}", [
                'file' => ['display_name' => $this->file->title ?? "Course_File_{$this->file->id}"]
            ]);

            $uploadUrl = $response->header('X-Goog-Upload-URL');

            if ($uploadUrl) {
                // 3. رفع محتوى الملف
                $uploadResponse = Http::withHeaders([
                    'Content-Length' => $numBytes,
                    'X-Goog-Upload-Offset' => '0',
                    'X-Goog-Upload-Command' => 'upload, finalize',
                ])->withBody(file_get_contents($tempPath), $mimeType)
                  ->post($uploadUrl);

                $fileData = $uploadResponse->json()['file'] ?? null;

                if ($fileData && isset($fileData['uri'])) {
                    CourseFileAiMeta::updateOrCreate(
                        ['course_file_id' => $this->file->id],
                        [
                            'google_file_uri' => $fileData['uri'],
                            'google_file_name' => $fileData['name'],
                            'expires_at' => now()->addHours(47), // صالحة لمدة 48 ساعة لدى جوجل
                        ]
                    );
                }
            }

            @unlink($tempPath);
        } catch (\Exception $e) {
            Log::error('Failed to process course file for AI', ['error' => $e->getMessage()]);
        }
    }
}