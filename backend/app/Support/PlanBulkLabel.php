<?php

namespace App\Support;

/**
 * نفس منطق bulkStateOf()/countedPhrase() بالضبط من frontend/plan-view.js
 * (خطوة ٩٨/١٠٠) — منقول حرفيًا لـPHP حتى تكون صياغة زر "منجز الكل"
 * الديناميكية (الجزئية) مطابقة تمامًا بين الموقع وبوت تيليجرام، بمصدر
 * واحد لكل جانب بدل نسختين قد تختلفان بالصياغة مع أي تعديل مستقبلي
 * على أحدهما فقط. أي تغيير على صياغة الموقع يجب أن يُنسخ هنا يدويًا
 * (لا توجد أداة تشارك كودًا بين JS وPHP بهذا المشروع) — وحدة الاختبار
 * (tests/Feature/PlanBulkLabelTest.php) تثبّت الأمثلة الأربعة من طلب
 * المستخدم الأصلي حرفيًا لتكشف أي انحراف لاحق.
 */
class PlanBulkLabel
{
    private const REMAINING_FORMS = [
        'one' => 'مساق واحد متبقٍ',
        'two' => 'مساقين متبقيات',
        'plural' => 'مساقات متبقية',
    ];

    private const DROPPED_FORMS = [
        'one' => 'مساق منسحب',
        'two' => 'مساقين منسحبين',
        'plural' => 'مساقات منسحبة',
    ];

    private const REGISTERED_FORMS = [
        'one' => 'مساق جارٍ',
        'two' => 'مساقين جاريين',
        'plural' => 'مساقات جارية',
    ];

    /**
     * @param  array<int, string>  $statuses  حالة كل مساق بهذا النطاق:
     *                                        'completed'|'registered'|'dropped'|'none'
     * @return array{state: 'default'|'done'|'partial', label: string}
     */
    public static function forStatuses(array $statuses): array
    {
        $counts = [
            'total' => count($statuses),
            'completed' => 0,
            'registered' => 0,
            'dropped' => 0,
            'none' => 0,
        ];

        foreach ($statuses as $status) {
            $key = in_array($status, ['completed', 'registered', 'dropped'], true) ? $status : 'none';
            $counts[$key]++;
        }

        return self::forCounts($counts);
    }

    /**
     * @param  array{total:int,completed:int,registered:int,dropped:int,none:int}  $counts
     * @return array{state: 'default'|'done'|'partial', label: string}
     */
    public static function forCounts(array $counts): array
    {
        if (! $counts['total']) {
            return ['state' => 'default', 'label' => 'منجز الكل'];
        }

        if ($counts['completed'] === $counts['total']) {
            return ['state' => 'done', 'label' => 'منجز الكل'];
        }

        if ($counts['completed'] === 0) {
            return ['state' => 'default', 'label' => 'منجز الكل'];
        }

        $onlyRegistered = $counts['registered'] > 0
            && $counts['dropped'] === 0
            && $counts['none'] === 0;

        if ($onlyRegistered) {
            $verb = $counts['registered'] === 1 ? 'ما زال' : 'ما زالت';

            $label = 'منجز لكن '.$verb.' '.self::countedPhrase($counts['registered'], self::REGISTERED_FORMS);

            return ['state' => 'partial', 'label' => $label];
        }

        $clauses = [];

        if ($counts['dropped'] > 0) {
            $clauses[] = self::countedPhrase($counts['dropped'], self::DROPPED_FORMS);
        }

        if ($counts['none'] > 0) {
            $clauses[] = self::countedPhrase($counts['none'], self::REMAINING_FORMS);
        }

        if ($counts['registered'] > 0) {
            $clauses[] = self::countedPhrase($counts['registered'], self::REGISTERED_FORMS);
        }

        return ['state' => 'partial', 'label' => 'منجز ما عدا '.implode(' و', $clauses)];
    }

    /**
     * @param  array{one: string, two: string, plural: string}  $forms
     */
    private static function countedPhrase(int $count, array $forms): string
    {
        if ($count === 1) {
            return $forms['one'];
        }

        if ($count === 2) {
            return $forms['two'];
        }

        return $count.' '.$forms['plural'];
    }
}
