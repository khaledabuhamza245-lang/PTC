<?php

namespace App\Services\Ai;

use App\Models\Course;
use App\Models\CourseFile;

/*
 * يطابق اسم ملف مذكور بنص حرّ (مثل: "اشرحلي ملف Chapter 4 بمادة قواعد
 * البيانات") مع ملف حقيقي منشور بقاعدة البيانات. مطابقة متحفّظة عمدًا:
 * كل كلمات عنوان الملف ذات المعنى (بعد التطبيع) لازم تظهر بنص الطالب،
 * وإلا بلا نتيجة — "ما لقيت الملف" صريح أفضل من تخمين غلط يشرح محتوى
 * ملف غير الذي قصده الطالب فعلًا.
 */
class CourseFileMatcher
{
    public static function find(string $message, ?string $courseNameHint = null): ?CourseFile
    {
        $normalizedMessage = self::normalize($message);
        if ($normalizedMessage === '') {
            return null;
        }

        $courseId = $courseNameHint ? self::matchCourseId($courseNameHint) : null;

        $query = CourseFile::query()
            ->where('is_published', true)
            ->where('status', 'ready')
            ->where('ai_summarizable', true)
            ->whereNotNull('title')
            ->where('title', '!=', '');

        if ($courseId) {
            $query->where('course_id', $courseId);
        }

        $candidates = $query->get(['id', 'title', 'course_id', 'external_url', 'mime_type']);

        $best = null;
        $bestScore = 0;

        foreach ($candidates as $file) {
            $titleTokens = array_values(array_filter(explode(' ', self::normalize($file->title))));
            if (count($titleTokens) === 0) {
                continue;
            }

            $allFound = true;
            foreach ($titleTokens as $token) {
                /* كلمات حرف واحد غير رقمية (أدوات ربط عادةً) تُتجاهل من
                   شرط "الكل لازم يظهر" — أرقام الفصل/الشابتر تبقى إلزامية
                   لأنها هي ما يميّز ملفًا عن آخر بنفس المادة. */
                if (mb_strlen($token) < 2 && !ctype_digit($token)) {
                    continue;
                }
                if (!str_contains($normalizedMessage, $token)) {
                    $allFound = false;
                    break;
                }
            }

            if ($allFound && count($titleTokens) > $bestScore) {
                $best = $file;
                $bestScore = count($titleTokens);
            }
        }

        /* لو حُصر البحث بمادة الطالب الحالية وفشل، جرّب كل المواد —
           الطالب قد يسأل من الصفحة الرئيسية أو عن ملف بمادة غير التي
           يستعرضها حاليًا. */
        if (!$best && $courseId) {
            return self::find($message, null);
        }

        return $best;
    }

    private static function matchCourseId(string $courseNameHint): ?int
    {
        return Course::query()
            ->where('name_ar', $courseNameHint)
            ->orWhere('name_en', $courseNameHint)
            ->value('id');
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}