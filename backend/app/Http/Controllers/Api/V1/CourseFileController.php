<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CourseFileController extends Controller
{
    public function index(Request $request, Course $course)
    {
        // المساق المعطّل يرد 404 في CourseController::show، فملفاته
        // يجب ألا تُسرد أيضًا — وإلا كان التعطيل بلا أثر.
        abort_unless($course->is_active, 404);

        $user = $request->user('sanctum');

        $isCourseStudent = $user?->myCourses()->whereKey($course->id)->exists() ?? false;

        $files = $course->files()
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (CourseFile $file) => $file->isVisibleTo($user, $isCourseStudent))
            ->values();

        return response()->json(['data' => $files]);
    }

    public function download(Request $request, CourseFile $courseFile)
    {
        $user = $request->user('sanctum');

        $isCourseStudent = $user?->myCourses()->whereKey($courseFile->course_id)->exists() ?? false;

        abort_unless(
            $courseFile->course?->is_active
                && $courseFile->isVisibleTo($user, $isCourseStudent),
            403,
        );

        if ($courseFile->external_url) {
            return response()->json(['data' => ['url' => $courseFile->external_url]]);
        }

        abort_unless($courseFile->storage_disk && $courseFile->storage_path, 404);

        $fileName = str_replace(["\r", "\n", '"'], '', $courseFile->original_name ?: $courseFile->title);
        $url = Storage::disk($courseFile->storage_disk)->temporaryUrl(
            $courseFile->storage_path,
            now()->addMinutes(config('files.download_url_minutes')),
            [
                'ResponseContentDisposition' => 'attachment; filename="'.$fileName.'"',
            ],
        );

        return response()->json(['data' => ['url' => $url]]);
    }
}
