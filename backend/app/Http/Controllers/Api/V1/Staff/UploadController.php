<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class UploadController extends Controller
{
    public function presign(Request $request)
    {
        $maxBytes = config('files.max_size_mb') * 1024 * 1024;

        $data = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'file_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', Rule::in(config('files.allowed_mime_types'))],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.$maxBytes],
        ]);

        $course = Course::findOrFail($data['course_id']);
        $extension = strtolower(pathinfo($data['file_name'], PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?: 'bin';
        $path = 'courses/'.$course->id.'/'.Str::uuid().'.'.$extension;
        $disk = config('filesystems.default');
        $expiresAt = now()->addMinutes(config('files.upload_url_minutes'));

        try {
            $upload = Storage::disk($disk)->temporaryUploadUrl($path, $expiresAt);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'تعذّر إنشاء رابط الرفع. تأكد من إعداد S3 أو R2.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'upload_url' => $upload['url'],
                'headers' => $upload['headers'],
                'storage_disk' => $disk,
                'storage_path' => $path,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }
}
