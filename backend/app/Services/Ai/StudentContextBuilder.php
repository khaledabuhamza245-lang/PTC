<?php

namespace App\Services\Ai;

use App\Models\Course;
use App\Models\GpaEntry;
use App\Models\ScheduleLecture;
use App\Models\User;
use App\Services\PlanCalculator;
use Illuminate\Support\Facades\DB;

/*
 * يبني نص السياق الشخصي المُرسَل مع كل سؤال لـGemini/OpenRouter —
 * بيانات حقيقية موجودة فعلًا بالنظام فقط. يشمل الآن (منذ إضافة جدول
 * gpa_entries — راجع gpaLine أدناه) معدّل الطالب التراكمي التقديري
 * كما أدخله بنفسه بحاسبة المعدل، بنفس صيغة 0-100 وبنفس منطق الحساب
 * المستخدم بـgpa.js/gpa-meter.js بالضبط (مساقات إجبارية كلها +
 * مساقات اختيارية بحدّ أقصى عدد المقاعد المسموحة بالخطة) — تكرار
 * متعمَّد للمنطق هنا (PHP) بدل استدعاء أي كود JS، بنفس فلسفة فصل
 * gpa_entries عن my_courses: حساب مستقل تمامًا، فأي تعديل مستقبلي
 * على حاسبة المعدل بالواجهة لا يكسر هذا الملف تلقائيًا (ولا العكس)،
 * بس يستوجب تحديث المنطقين معًا لو تغيّرت قاعدة الحساب نفسها.
 */
class StudentContextBuilder
{
    private const DAY_NAMES = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    /* نفس عتبات statusLabel/statusIcon بـgpa-meter.js بالضبط. */
    private const GPA_MILESTONES = [70, 85, 95];

    public static function build(User $user, PlanCalculator $calculator): string
    {
        $name = trim((string) $user->full_name) ?: 'الطالب';
        $coursesLine = self::currentTermCoursesLine($user);
        $summary = $calculator->summarize($user);
        $scheduleLine = self::scheduleLine($user->id);
        $gpaLine = self::gpaLine($user, $summary);

        return <<<TEXT
معلومات الطالب الحالي (بيانات حقيقية من نظام PTC Hub — استخدمها مباشرة، ولا تقل إنك لا تملك وصولًا لها):
- الاسم: {$name}
- مساقات الفصل الحالي: {$coursesLine}
- الساعات المُنجزة: {$summary['completed_hours']} من أصل {$summary['total_credit_hours']} ({$summary['percent']}٪)
- الساعات المتبقية للتخرّج: {$summary['remaining_hours']}
- الجدول الأسبوعي: {$scheduleLine}
- المعدل التراكمي (حسب حاسبة المعدل التي يعبّئها الطالب بنفسه):
{$gpaLine}

خاطب الطالب باسمه الأول عند بداية الحديث، واستخدم بياناته أعلاه لتخصيص نصيحتك عندما يفيد السؤال، بلا إقحامها بكل رد بلا داعٍ لذلك. لو سأل عن معدله أو كيف يرفعه أو أي مساق يركّز عليه، اعتمد على أرقام المعدل التراكمي أعلاه بدقة (لا تختلق رقمًا آخر)، واقترح نصيحة عملية ومحدّدة بدل كلام عام — مثل أي مساق ضعيف يحتاج تركيزًا أكبر، أو أي معدل يحتاجه بالمساقات المتبقية للوصول لمستوى أعلى، أو تشجيع لو كان أداؤه جيدًا أصلًا.
TEXT;
    }

    /*
     * يجب أن يطابق هذا بالضبط منطق ودجة «مساقاتي الحالية» بالصفحة
     * الرئيسية (isCurrentEnrollment بـauth.js): أي تسجيل بـmy_courses
     * حالته ليست completed ولا dropped — بلا أي شرط على term_id.
     *
     * (النسخة السابقة كانت تشترط أيضًا my_courses.term_id = المستخدم
     * current_term_id، وهذا شرط لا تطبّقه الودجة نفسها إطلاقًا — فطالب
     * عنده تسجيلات صحيحة بحالة registered لكن term_id مختلف عن
     * current_term_id (أو NULL) كانت تظهر له الودجة مساقاته بالصفحة
     * الرئيسية، بينما المساعد يقول له "لا توجد مساقات مسجّلة"، وهذا
     * بالضبط ما لاحظه الاختبار.)
     */
    private static function currentTermCoursesLine(User $user): string
    {
        $names = DB::table('my_courses')
            ->join('courses', 'my_courses.course_id', '=', 'courses.id')
            ->where('my_courses.user_id', $user->id)
            ->whereNotIn('my_courses.status', ['completed', 'dropped'])
            ->pluck('courses.name_ar')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $names ? implode('، ', $names) : 'لا توجد مساقات مسجّلة حاليًا';
    }

    private static function scheduleLine(int $userId): string
    {
        $lectures = ScheduleLecture::where('user_id', $userId)->orderBy('start_time')->get();

        if ($lectures->isEmpty()) {
            return 'لم يُدخل جدولًا أسبوعيًا بعد';
        }

        return $lectures->map(function (ScheduleLecture $lecture) {
            $days = collect($lecture->days ?? [])
                ->map(fn ($d) => self::DAY_NAMES[$d] ?? null)
                ->filter()
                ->implode('/');

            return trim("{$lecture->name} ({$days} {$lecture->start_time}-{$lecture->end_time})");
        })->implode('، ');
    }

    /*
     * $planSummary هو نفسه ناتج PlanCalculator::summarize() المحسوب
     * أصلًا بدالة build() أعلاه — يُعاد استخدامه هنا بدل استدعاء ثانٍ،
     * لأنه يحمل بالفعل total_credit_hours وelectives.allowed اللازمين
     * لنفس حدود gpa.js (عدد المقاعد الاختيارية المسموحة فعليًا بالخطة،
     * لا كل الاختياريات المتاحة بالكتالوج).
     */
    private static function gpaLine(User $user, array $planSummary): string
    {
        $entries = GpaEntry::where('user_id', $user->id)->with('course')->get();

        if ($entries->isEmpty()) {
            return '  لم يُدخل أي علامات بحاسبة المعدل التراكمي بعد.';
        }

        $grades = [];
        foreach ($entries as $entry) {
            if ($entry->course) {
                $grades[$entry->course->key] = (float) $entry->grade;
            }
        }

        if (!$grades) {
            return '  لم يُدخل أي علامات بحاسبة المعدل التراكمي بعد.';
        }

        $courses = Course::query()->where('is_active', true)
            ->get(['id', 'key', 'name_ar', 'credit_hours', 'course_type']);

        $allowedSlots = (int) (($planSummary['electives']['allowed'] ?? null) ?: ($planSummary['required_electives'] ?? 5));
        $totalPlanHours = (float) ($planSummary['total_credit_hours'] ?? 0);

        $weightedSum = 0.0;
        $gradedHours = 0.0;
        $weak = [];
        $strong = [];

        foreach ($courses->where('course_type', 'required') as $course) {
            if (!array_key_exists($course->key, $grades)) continue;

            $grade = $grades[$course->key];
            $hours = (float) $course->credit_hours;
            $weightedSum += $grade * $hours;
            $gradedHours += $hours;

            if ($grade < 70) $weak[] = ['name' => $course->name_ar, 'grade' => $grade];
            if ($grade >= 90) $strong[] = ['name' => $course->name_ar, 'grade' => $grade];
        }

        $gradedElectives = [];
        foreach ($courses->where('course_type', 'elective') as $course) {
            if (array_key_exists($course->key, $grades)) {
                $gradedElectives[] = ['course' => $course, 'grade' => $grades[$course->key]];
            }
        }

        /* بحدّ أقصى عدد المقاعد الاختيارية المسموحة — بنفس ترتيب
           gradedElectives.slice(0, allowedSlots) بـgpa.js/gpa-meter.js. */
        foreach (array_slice($gradedElectives, 0, $allowedSlots) as $entry) {
            $grade = $entry['grade'];
            $hours = (float) $entry['course']->credit_hours;
            $weightedSum += $grade * $hours;
            $gradedHours += $hours;

            if ($grade < 70) $weak[] = ['name' => $entry['course']->name_ar, 'grade' => $grade];
            if ($grade >= 90) $strong[] = ['name' => $entry['course']->name_ar, 'grade' => $grade];
        }

        if ($gradedHours <= 0) {
            return '  لم يُدخل أي علامات محتسبة بحاسبة المعدل التراكمي بعد.';
        }

        $avg = $weightedSum / $gradedHours;
        $remainingHours = max(0.0, $totalPlanHours - $gradedHours);

        $lines = [];
        $lines[] = sprintf(
            '  المعدل الحالي: %s من 100 (%s) — محسوب على %d ساعة مُدخَل لها علامة من أصل %d ساعة بالخطة (%d ساعة متبقية بلا علامة بعد).',
            self::fmt($avg),
            self::gpaStatusLabel($avg),
            (int) round($gradedHours),
            (int) round($totalPlanHours),
            (int) round($remainingHours)
        );

        if ($weak) {
            usort($weak, fn ($a, $b) => $a['grade'] <=> $b['grade']);
            $weakList = collect($weak)->take(3)
                ->map(fn ($w) => "{$w['name']} ({$w['grade']})")
                ->implode('، ');
            $lines[] = "  أضعف المساقات المُدخَلة حاليًا (أقل من 70): {$weakList}.";
        }

        if ($strong) {
            usort($strong, fn ($a, $b) => $b['grade'] <=> $a['grade']);
            $strongList = collect($strong)->take(2)
                ->map(fn ($w) => "{$w['name']} ({$w['grade']})")
                ->implode('، ');
            $lines[] = "  أقوى المساقات المُدخَلة: {$strongList}.";
        }

        if ($remainingHours > 0) {
            $totalHoursForTarget = $gradedHours + $remainingHours;

            foreach (self::GPA_MILESTONES as $milestone) {
                if ($avg >= $milestone) continue;

                $needed = ($milestone * $totalHoursForTarget - $weightedSum) / $remainingHours;

                if ($needed > 100) {
                    $lines[] = "  الوصول لمعدل {$milestone} لم يعد ممكنًا رياضيًا بالاعتماد على الساعات المتبقية فقط (حتى بعلامة 100 كاملة بكلها) — يحتاج أيضًا تحسين علامة مساق سابق (إعادة مادة) لا القادم فقط.";
                } else {
                    $lines[] = sprintf(
                        '  للوصول لمعدل %d، يحتاج معدل %s على الأقل بكل الساعات المتبقية (%d ساعة).',
                        $milestone,
                        self::fmt($needed),
                        (int) round($remainingHours)
                    );
                }

                break; /* أقرب مستوى تالٍ فقط، لا الثلاثة كلهم دفعة وحدة. */
            }
        } else {
            $lines[] = '  كل ساعات الخطة تقريبًا معلَّم عليها بالفعل — هذا الرقم يقارب معدله التراكمي النهائي المتوقع.';
        }

        return implode("\n", $lines);
    }

    /* نفس عتبات statusLabel بـgpa-meter.js بالضبط (60/70/85/95). */
    private static function gpaStatusLabel(float $avg): string
    {
        if ($avg < 60) return 'بحاجة لتحسين';
        if ($avg < 70) return 'بداية جيدة';
        if ($avg < 85) return 'أداء جيد';
        if ($avg < 95) return 'متفوّق';
        return 'استثنائي';
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}