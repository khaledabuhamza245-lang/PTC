<?php

namespace Tests\Feature;

use App\Support\PlanBulkLabel;
use Tests\TestCase;

/**
 * يثبّت أن App\Support\PlanBulkLabel (المنقولة من frontend/plan-view.js
 * bulkStateOf()) تنتج نفس الصياغات الأربع اللي حدَّدها المستخدم حرفيًا
 * بطلبه الأصلي (خطوة ٩٧)، ونفس حالتي "منجز الكل"/"افتراضي" الأساسيتين.
 */
class PlanBulkLabelTest extends TestCase
{
    public function test_no_courses_is_the_default_state(): void
    {
        $this->assertSame(
            ['state' => 'default', 'label' => 'منجز الكل'],
            PlanBulkLabel::forStatuses([]),
        );
    }

    public function test_nothing_completed_is_the_default_state(): void
    {
        $result = PlanBulkLabel::forStatuses(['registered', 'none', 'dropped']);

        $this->assertSame('default', $result['state']);
    }

    public function test_all_completed_is_the_done_state(): void
    {
        $result = PlanBulkLabel::forStatuses(['completed', 'completed', 'completed']);

        $this->assertSame('done', $result['state']);
        $this->assertSame('منجز الكل', $result['label']);
    }

    /** "منجز ما عدا مساقين متبقيات" — مثنّى صريح كما طلب المستخدم بالضبط. */
    public function test_two_remaining_courses_uses_the_explicit_dual_form(): void
    {
        $result = PlanBulkLabel::forStatuses(['completed', 'completed', 'completed', 'completed', 'none', 'none']);

        $this->assertSame('partial', $result['state']);
        $this->assertSame('منجز ما عدا مساقين متبقيات', $result['label']);
    }

    /** "منجز لكن ما زال مساق جارٍ" — حالة واحد جارٍ وحيد بلا منسحب ولا متبقٍ. */
    public function test_a_single_in_progress_course_uses_the_still_ongoing_phrasing(): void
    {
        $result = PlanBulkLabel::forStatuses(['completed', 'completed', 'registered']);

        $this->assertSame('partial', $result['state']);
        $this->assertSame('منجز لكن ما زال مساق جارٍ', $result['label']);
    }

    /** "منجز ما عدا مساق منسحب" — مفرد. */
    public function test_a_single_dropped_course_uses_the_singular_form(): void
    {
        $result = PlanBulkLabel::forStatuses(['completed', 'completed', 'dropped']);

        $this->assertSame('partial', $result['state']);
        $this->assertSame('منجز ما عدا مساق منسحب', $result['label']);
    }

    /** ثلاثة فأكثر: رقم صريح + جمع. */
    public function test_three_or_more_uses_the_numeric_plural_form(): void
    {
        $result = PlanBulkLabel::forStatuses(['completed', 'none', 'none', 'none']);

        $this->assertSame('منجز ما عدا 3 مساقات متبقية', $result['label']);
    }

    /** الحالة المركّبة (منسحب + متبقٍ + جارٍ معًا) بترتيب الأولوية الصحيح. */
    public function test_mixed_dropped_remaining_and_registered_join_with_and(): void
    {
        $result = PlanBulkLabel::forStatuses([
            'completed', 'completed',
            'dropped', 'dropped', 'dropped',
            'none',
            'registered',
        ]);

        $this->assertSame(
            'منجز ما عدا 3 مساقات منسحبة ومساق واحد متبقٍ ومساق جارٍ',
            $result['label'],
        );
    }
}
