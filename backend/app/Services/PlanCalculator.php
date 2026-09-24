<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Course;
use App\Models\User;

/**
 * حاسبة تقدّم الطالب نحو التخرّج (مُحسّنة للسرعة والأداء).
 */
class PlanCalculator
{
    private const DEFAULT_TOTAL_HOURS = 142;
    private const DEFAULT_ELECTIVE_SLOTS = 5;

    /**
     * ذاكرة مؤقتة لتقليل استعلامات قاعدة البيانات أثناء الطلب الواحد.
     */
    private ?array $userEnrollmentsCache = null;
    private ?int $cachedUserId = null;

    public function summarize(User $user): array
    {
        $totalHours = AppSetting::int('program.total_credit_hours', self::DEFAULT_TOTAL_HOURS);
        $electiveSlots = AppSetting::int('program.required_electives', self::DEFAULT_ELECTIVE_SLOTS);

        $catalog = Course::query()
            ->where('is_active', true)
            ->orderBy('year')
            ->orderBy('semester')
            ->orderBy('id')
            ->get(['id', 'key', 'code', 'year', 'semester', 'credit_hours', 'course_type']);

        $enrollments = $this->enrollments($user);

        $completedRequired = [];
        $completedElectives = [];
        $counts = ['completed' => 0, 'registered' => 0, 'dropped' => 0];

        foreach ($catalog as $course) {
            $row = $enrollments[$course->id] ?? null;
            if (! $row) {
                continue;
            }

            $status = $row['status'];
            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }

            if ($status !== 'completed' || $course->course_type === 'placeholder') {
                continue;
            }

            if ($course->course_type === 'elective') {
                $completedElectives[] = ['course' => $course, 'completed_at' => $row['completed_at']];
            } else {
                $completedRequired[] = $course;
            }
        }

        usort($completedElectives, function ($a, $b) {
            $left = $a['completed_at'];
            $right = $b['completed_at'];

            if ($left === $right) {
                return $a['course']->id <=> $b['course']->id;
            }

            if ($left === null) {
                return 1;
            }

            if ($right === null) {
                return -1;
            }

            return strcmp($left, $right);
        });

        $creditedElectives = array_slice($completedElectives, 0, $electiveSlots);

        $slots = $catalog
            ->where('course_type', 'placeholder')
            ->sortBy([['year', 'asc'], ['semester', 'asc'], ['id', 'asc']])
            ->values();

        $electiveHoursByYear = [];
        foreach ($creditedElectives as $index => $entry) {
            $slot = $slots[$index] ?? null;
            $year = $slot?->year ?? $entry['course']->year;
            $electiveHoursByYear[$year] = ($electiveHoursByYear[$year] ?? 0)
                + (int) $entry['course']->credit_hours;
        }

        $requiredHours = array_sum(array_map(
            fn (Course $course) => (int) $course->credit_hours,
            $completedRequired,
        ));

        $electiveHours = array_sum(array_map(
            fn (array $entry) => (int) $entry['course']->credit_hours,
            $creditedElectives,
        ));

        $completedHours = $requiredHours + $electiveHours;

        return [
            'total_credit_hours' => $totalHours,
            'required_electives' => $electiveSlots,
            'completed_hours' => $completedHours,
            'remaining_hours' => max(0, $totalHours - $completedHours),
            'percent' => $totalHours > 0
                ? min(100, (int) round($completedHours / $totalHours * 100))
                : 0,
            'counts' => $counts,
            'electives' => [
                'counted' => count($creditedElectives),
                'allowed' => $electiveSlots,
                'extra' => max(0, count($completedElectives) - $electiveSlots),
            ],
            'courses_missing_hours' => $catalog
                ->whereIn('course_type', ['required', 'elective'])
                ->whereNull('credit_hours')
                ->count(),
            'by_year' => $this->byYear($catalog, $completedRequired, $electiveHoursByYear),
        ];
    }

    /**
     * حالة كل مساق سجّله الطالب، تُستخرج من الذاكرة المؤقتة مباشرة.
     */
    public function statusMap(User $user): array
    {
        $this->loadUserEnrollments($user);

        $rows = [];
        foreach ($this->userEnrollmentsCache as $data) {
            $rows[$data['key']] = [
                'status' => $data['status'],
                'completed_at' => $data['completed_at'],
                'grade' => $data['grade'],
                'term_id' => $data['term_id'],
            ];
        }

        return $rows;
    }

    /**
     * جلب تسجيلات الطالب مع تخزينها مؤقتاً لتجنب تكرار الاستعلام.
     */
    private function enrollments(User $user): array
    {
        $this->loadUserEnrollments($user);

        $rows = [];
        foreach ($this->userEnrollmentsCache as $courseId => $data) {
            $rows[$courseId] = [
                'status' => $data['status'],
                'completed_at' => $data['completed_at'],
            ];
        }

        return $rows;
    }

    /**
     * استعلام واحد موحد ينفذ لمرة واحدة فقط لجميع بيانات الطالب.
     */
    private function loadUserEnrollments(User $user): void
    {
        if ($this->cachedUserId === $user->id && $this->userEnrollmentsCache !== null) {
            return;
        }

        $this->cachedUserId = $user->id;
        $this->userEnrollmentsCache = [];

        $courses = $user->myCourses()
            ->get(['courses.id', 'courses.key']);

        foreach ($courses as $course) {
            $this->userEnrollmentsCache[$course->id] = [
                'key' => $course->key,
                'status' => $this->normalizeStatus($course->pivot->status),
                'completed_at' => $course->pivot->completed_at,
                'grade' => $course->pivot->grade,
                'term_id' => $course->pivot->term_id,
            ];
        }
    }

    private function normalizeStatus(?string $status): string
    {
        return in_array($status, ['registered', 'completed', 'dropped'], true)
            ? $status
            : 'registered';
    }

    private function byYear($catalog, array $completedRequired, array $electiveHoursByYear): array
    {
        $completedIds = array_map(fn (Course $course) => $course->id, $completedRequired);

        $years = $catalog
            ->whereIn('course_type', ['required', 'placeholder'])
            ->groupBy('year')
            ->sortKeys();

        $output = [];

        foreach ($years as $year => $courses) {
            $planHours = $courses->sum(fn (Course $course) => (int) $course->credit_hours);

            $doneHours = $courses
                ->filter(fn (Course $course) => in_array($course->id, $completedIds, true))
                ->sum(fn (Course $course) => (int) $course->credit_hours);

            $doneHours += $electiveHoursByYear[$year] ?? 0;

            $output[] = [
                'year' => (int) $year,
                'plan_hours' => $planHours,
                'completed_hours' => $doneHours,
                'percent' => $planHours > 0 ? min(100, (int) round($doneHours / $planHours * 100)) : 0,
            ];
        }

        return $output;
    }
}