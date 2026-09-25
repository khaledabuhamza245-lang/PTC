<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Course;
use App\Models\GpaEntry;
use App\Models\User;

/*
 * حاسبة المعدل التراكمي لأمر "معدلي" داخل بوت تيليجرام.
 *
 * خدمة معزولة تمامًا عن GpaController — العلامات نفسها مخزَّنة فعليًا
 * بالخادم (GpaEntry، عبر GpaController) ويُعاد استخدامها هون كما هي،
 * لكن كل حساب المعدل (المجموع الموزون، تقسيم الفصل/السنة، سقف
 * المقاعد الاختيارية...) موجود فقط بالمتصفح ضمن frontend/gpa.js
 * (window.Gpa.computeStats) — لا طريقة لاستدعائه من الخادم لأنه كود
 * جافاسكربت صرف. لذلك أُعيد بناء نفس المنطق بالضبط بلغة PHP هون.
 *
 * القرار (بعد نقاش الخيارين مع الطالب ضمنيًا عبر نمط اختياراته
 * السابقة بكل مراحل هذا البوت — دومًا الخيار الأشمل: كل الأدوات
 * الأربعة بالقائمة الذكية، CRUD كامل للجدول لا عرض فقط...): بناء
 * تطابق كامل مع صفحة الموقع (معدل عام + تفصيل لكل سنة وفصل) بدل نسخة
 * مبسطة بمعدل واحد فقط.
 *
 * تنبيه صيانة: أي تعديل مستقبلي على صيغة الحساب بـgpa.js (مثلًا تغيير
 * طريقة احتساب المقاعد الاختيارية الزائدة) يجب أن ينعكس هون يدويًا
 * أيضًا — هذا تكرار منطق مقصود ومقبول لأن الطرفين لا يشتركان بنفس
 * بيئة التشغيل (متصفح مقابل خادم).
 */
class TelegramGpaCalculator
{
    private const DEFAULT_TOTAL_HOURS = 142;
    private const DEFAULT_ELECTIVE_SLOTS = 5;
    private const WARNING_THRESHOLD = 60;
    private const GOOD_THRESHOLD = 75;

    /**
     * يرجّع مصفوفة إحصاءات خام (بلا أي تنسيق نصي) — ['has_grades' => false]
     * إذا الطالب لسا ما سجّل ولا علامة واحدة بحاسبة المعدل بالموقع.
     */
    public function summarize(User $user): array
    {
        $totalCreditHours = AppSetting::int('program.total_credit_hours', self::DEFAULT_TOTAL_HOURS);
        $allowedElectiveSlots = AppSetting::int('program.required_electives', self::DEFAULT_ELECTIVE_SLOTS);

        /*
         * is_active=true فقط — نفس فلترة /courses العامة (CourseController)
         * التي تعتمد عليها الواجهة أصلًا عبر PTCAuth.getCourses().
         */
        $courses = Course::query()
            ->where('is_active', true)
            ->get(['id', 'key', 'year', 'semester', 'sort_order', 'credit_hours', 'course_type']);

        $required = $courses->where('course_type', 'required')
            ->sortBy([['semester', 'asc'], ['sort_order', 'asc'], ['id', 'asc']])
            ->values();

        $elective = $courses->where('course_type', 'elective')->values();

        $grades = GpaEntry::query()
            ->where('user_id', $user->id)
            ->with('course:id,key')
            ->get()
            ->filter(fn (GpaEntry $entry) => $entry->course !== null)
            ->mapWithKeys(fn (GpaEntry $entry) => [$entry->course->key => (float) $entry->grade]);

        if ($grades->isEmpty()) {
            return ['has_grades' => false];
        }

        $weightedSum = 0.0;
        $gradedHours = 0;
        $requiredPlanHours = 0;
        $bySemester = []; // semester => ['weightedSum','hours','year']
        $byYear = [];     // year => ['weightedSum','hours']

        foreach ($required as $course) {
            $hours = (int) ($course->credit_hours ?? 0);
            $requiredPlanHours += $hours;

            if (! $grades->has($course->key)) {
                continue;
            }

            $grade = $grades->get($course->key);
            $points = $grade * $hours;
            $weightedSum += $points;
            $gradedHours += $hours;

            $semester = (int) $course->semester;
            $bySemester[$semester] ??= ['weightedSum' => 0.0, 'hours' => 0, 'year' => (int) $course->year];
            $bySemester[$semester]['weightedSum'] += $points;
            $bySemester[$semester]['hours'] += $hours;

            $year = (int) $course->year;
            $byYear[$year] ??= ['weightedSum' => 0.0, 'hours' => 0];
            $byYear[$year]['weightedSum'] += $points;
            $byYear[$year]['hours'] += $hours;
        }

        $gradedElectives = [];
        foreach ($elective as $course) {
            if (! $grades->has($course->key)) {
                continue;
            }

            $gradedElectives[] = [
                'grade' => $grades->get($course->key),
                'hours' => (int) ($course->credit_hours ?? 0),
            ];
        }

        /*
         * نفس منطق gpa.js بالضبط: أول allowedElectiveSlots مساق اختياري
         * معلَّم بعلامة يدخل الحساب، الباقي يُعرض بس ما بيُحتسب — مافي
         * ترتيب رسمي هون (عكس الموقع يلي بيرتب حسب ترتيب الإدخال بالجدول)
         * فنكتفي بترتيب استرجاع القاعدة، وهذا اختلاف بسيط لا يغيّر الرقم
         * النهائي (المجموع نفسه بغض النظر عن أي مساق "زائد" استُبعد).
         */
        $creditedElectives = array_slice($gradedElectives, 0, $allowedElectiveSlots);
        $extraElectivesCount = max(0, count($gradedElectives) - $allowedElectiveSlots);

        foreach ($creditedElectives as $entry) {
            $weightedSum += $entry['grade'] * $entry['hours'];
            $gradedHours += $entry['hours'];
        }

        $totalPlanHours = $totalCreditHours > 0
            ? $totalCreditHours
            : $requiredPlanHours + $allowedElectiveSlots * 3;

        $cumulativeAvg = $gradedHours > 0 ? $weightedSum / $gradedHours : null;
        $remainingHours = max(0, $totalPlanHours - $gradedHours);

        ksort($bySemester);
        ksort($byYear);

        $semesterAverages = [];
        foreach ($bySemester as $semester => $entry) {
            $semesterAverages[] = [
                'semester' => $semester,
                'year' => $entry['year'],
                'average' => $entry['hours'] > 0 ? $entry['weightedSum'] / $entry['hours'] : null,
                'hours' => $entry['hours'],
            ];
        }

        $yearAverages = [];
        foreach ($byYear as $year => $entry) {
            $yearAverages[] = [
                'year' => $year,
                'average' => $entry['hours'] > 0 ? $entry['weightedSum'] / $entry['hours'] : null,
                'hours' => $entry['hours'],
            ];
        }

        return [
            'has_grades' => true,
            'weighted_sum' => $weightedSum,
            'graded_hours' => $gradedHours,
            'total_plan_hours' => $totalPlanHours,
            'remaining_hours' => $remainingHours,
            'cumulative_avg' => $cumulativeAvg,
            'semester_averages' => $semesterAverages,
            'year_averages' => $yearAverages,
            'extra_electives_count' => $extraElectivesCount,
        ];
    }

    /**
     * النص الجاهز لإرساله عبر TelegramBotApi::sendMessage (HTML parse_mode).
     */
    public function formatForTelegram(User $user): string
    {
        $stats = $this->summarize($user);

        if (! $stats['has_grades']) {
            return "📊 لسا ما سجّلت أي علامة بحاسبة المعدل بالموقع.\n\n".
                "روح لصفحة \"حاسبة المعدل\" بالموقع وسجّل علاماتك، وبعدها اكتب لي \"معدلي\" هون وبحسبلك المعدل فورًا 🎯";
        }

        $lines = [];
        $lines[] = '📊 <b>معدّلك التراكمي</b>';
        $lines[] = '';
        $lines[] = $this->gradeEmoji($stats['cumulative_avg']).' المعدل العام: <b>'.$this->fmt($stats['cumulative_avg']).'</b>';
        $lines[] = "🕐 الساعات المُنجزة: {$stats['graded_hours']} من {$stats['total_plan_hours']}";

        if ($stats['remaining_hours'] > 0) {
            $lines[] = "⏳ الساعات المتبقية: {$stats['remaining_hours']}";
        }

        if ($stats['extra_electives_count'] > 0) {
            $lines[] = "ℹ️ عندك {$stats['extra_electives_count']} مساق اختياري بعلامة زيادة عن العدد المطلوب بالخطة — ما بيدخل بحساب المعدل.";
        }

        if (! empty($stats['year_averages'])) {
            $lines[] = '';
            $lines[] = '📚 <b>حسب السنة:</b>';
            foreach ($stats['year_averages'] as $entry) {
                $lines[] = $this->gradeEmoji($entry['average']).' '.$this->yearLabel($entry['year']).': '.
                    '<b>'.$this->fmt($entry['average']).'</b>'." ({$entry['hours']} ساعة)";
            }
        }

        if (! empty($stats['semester_averages'])) {
            $lines[] = '';
            $lines[] = '📅 <b>حسب الفصل:</b>';
            foreach ($stats['semester_averages'] as $entry) {
                $label = $this->yearLabel($entry['year']).' — '.$this->semesterLabel($entry['semester']);
                $lines[] = $this->gradeEmoji($entry['average']).' '.$label.': '.
                    $this->fmt($entry['average'])." ({$entry['hours']} ساعة)";
            }
        }

        $lines[] = '';
        $lines[] = '💡 هاي نفس البيانات المحفوظة بحاسبة المعدل على الموقع — أي تعديل هون أو هناك بينعكس بالمكانين فورًا.';

        return implode("\n", $lines);
    }

    private function gradeEmoji(?float $avg): string
    {
        if ($avg === null) {
            return '⚪';
        }

        if ($avg < self::WARNING_THRESHOLD) {
            return '🔴';
        }

        if ($avg < self::GOOD_THRESHOLD) {
            return '🟡';
        }

        return '🟢';
    }

    private function fmt(?float $n): string
    {
        if ($n === null) {
            return '—';
        }

        $formatted = number_format($n, 2, '.', '');
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, '.');

        return $formatted;
    }

    private function yearLabel(int $year): string
    {
        $labels = [1 => 'السنة الأولى', 2 => 'السنة الثانية', 3 => 'السنة الثالثة', 4 => 'السنة الرابعة'];

        return $labels[$year] ?? "السنة {$year}";
    }

    private function semesterLabel(int $semester): string
    {
        return (($semester - 1) % 2 === 0) ? 'الفصل الأول' : 'الفصل الثاني';
    }
}
