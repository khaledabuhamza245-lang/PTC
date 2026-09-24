<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramUploadController extends Controller
{
    public function __invoke(Request $request, Course $course)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'يجب تسجيل الدخول أولاً.',
            ], 401);
        }

        $webAppUrl = (string) config(
            'services.telegram_contributions.web_app_url',
            ''
        );

        $sharedSecret = (string) config(
            'services.telegram_contributions.shared_secret',
            ''
        );

        if ($webAppUrl === '' || $sharedSecret === '') {
            return response()->json([
                'message' => 'إعدادات بوت تيليجرام غير مكتملة.',
            ], 500);
        }

        $studentName = trim(
            (string) ($user->name ?? '')
        );

        if ($studentName === '') {
            $studentName = trim(implode(' ', array_filter([
                $user->first_name ?? null,
                $user->father_name ?? null,
                $user->last_name ?? null,
            ])));
        }

        if ($studentName === '') {
            $studentName = (string) $user->email;
        }

        $courseName =
    $course->name_ar
    ?? $course->name_en
    ?? $course->code
    ?? 'المادة';;

        $payloadData = [
            'website_user_id' => (string) $user->id,
            'student_name' => $studentName,
            'student_email' => (string) $user->email,

            'course_id' => (string) (
                $course->key
                ?? $course->id
            ),

            'course_code' => (string) (
                $course->code
                ?? ''
            ),

            'course_name' => (string) $courseName,

            // صلاحية رابط الموقع قبل إنشاء Session البوت.
            'exp' => now()
                ->addMinutes(5)
                ->timestamp,
        ];

        $json = json_encode(
            $payloadData,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );

        $payload = rtrim(
            strtr(
                base64_encode($json),
                '+/',
                '-_'
            ),
            '='
        );

        $signature = hash_hmac(
            'sha256',
            $payload,
            $sharedSecret
        );

        try {
            $response = Http::timeout(15)
                ->withOptions([
                    'allow_redirects' => true,
                ])
                ->get($webAppUrl, [
                    'payload' => $payload,
                    'signature' => $signature,
                    'mode' => 'json',
                ]);

            if (! $response->successful()) {
                throw new RuntimeException(
                    'تعذّر الاتصال بخدمة تيليجرام.'
                );
            }

            $result = $response->json();

            if (
                ! is_array($result)
                || ! ($result['ok'] ?? false)
            ) {
                throw new RuntimeException(
                    $result['message']
                    ?? 'تعذّر إنشاء جلسة المشاركة.'
                );
            }

            $telegramUrl =
                $result['data']['url']
                ?? null;

            if (
                ! is_string($telegramUrl)
                || ! str_starts_with(
                    $telegramUrl,
                    'https://t.me/'
                )
            ) {
                throw new RuntimeException(
                    'رابط تيليجرام غير صالح.'
                );
            }

            return response()->json([
                'data' => [
                    'url' => $telegramUrl,
                    'expires_at' =>
                        $result['data']['expires_at']
                        ?? null,
                ],
            ]);

        } catch (\Throwable $error) {
            report($error);

            return response()->json([
                'message' =>
                    'تعذّر إنشاء جلسة المشاركة مع بوت تيليجرام.',
            ], 502);
        }
    }
}