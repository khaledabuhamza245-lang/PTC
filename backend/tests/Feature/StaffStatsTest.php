<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * أرقام لوحة التحكم ورسم الانضمامات.
 *
 * الحراسة هنا على شكل الحمولة لا على قيمها وحدها: الواجهة ترسم أعمدة
 * بلا تحقق، فسلسلة ناقصة يومًا أو توزيعٌ تسقط منه سنة فارغة يخرجان
 * رسمًا صحيح المظهر خاطئ المعنى — وهو فشل لا يُرى بالعين.
 */
class StaffStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_reports_every_year_even_when_empty(): void
    {
        User::factory()->create(['role' => 'student', 'year' => 1]);
        User::factory()->create(['role' => 'student', 'year' => 1]);
        User::factory()->create(['role' => 'student', 'year' => 3]);
        User::factory()->create(['role' => 'student', 'year' => null]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $byYear = $this->getJson('/api/v1/staff/dashboard')
            ->assertOk()
            ->json('data.students_by_year');

        /* أربع سنوات + خانة «غير محدَّدة» — دائمًا خمسة صفوف. */
        $this->assertCount(5, $byYear);

        $totals = collect($byYear)->pluck('total', 'year');

        $this->assertSame(2, $totals[1]);
        $this->assertSame(0, $totals[2], 'السنة الفارغة يجب أن تُعاد بصفر لا أن تسقط');
        $this->assertSame(1, $totals[3]);
        $this->assertSame(0, $totals[4]);
        $this->assertSame(1, $totals[''], 'من سجّل بلا سنة يجب أن يُعدّ لا أن يختفي');
    }

    public function test_dashboard_counts_recent_signups(): void
    {
        User::factory()->create(['role' => 'student', 'created_at' => now()->subHours(3)]);
        User::factory()->create(['role' => 'student', 'created_at' => now()->subDays(3)]);
        User::factory()->create(['role' => 'student', 'created_at' => now()->subDays(30)]);

        /* المدير يُنشأ الآن — ولا يُعدّ، فالعدّان للطلاب وحدهم. */
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $data = $this->getJson('/api/v1/staff/dashboard')->assertOk()->json('data');

        $this->assertSame(1, $data['signups_24h']);
        $this->assertSame(2, $data['signups_7d']);
    }

    public function test_signup_series_is_dense_and_ordered(): void
    {
        User::factory()->create(['role' => 'student', 'created_at' => now()->subDays(2)]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $data = $this->getJson('/api/v1/staff/stats/signups?days=7')
            ->assertOk()
            ->json('data');

        $this->assertSame(7, $data['days']);
        $this->assertCount(7, $data['series'], 'كل يوم في المدى حاضر ولو بصفر');

        $dates = array_column($data['series'], 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates, 'السلسلة مرتّبة تصاعديًا');

        $this->assertSame(now()->toDateString(), end($dates), 'آخر يوم هو اليوم');

        /* المدير المصادِق لا يُعدّ — السلسلة للطلاب وحدهم. */
        $this->assertSame(1, $data['total']);
    }

    public function test_signup_series_range_is_capped(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/v1/staff/stats/signups?days=9999')
            ->assertOk()
            ->assertJsonPath('data.days', 90);

        $this->getJson('/api/v1/staff/stats/signups?days=0')
            ->assertOk()
            ->assertJsonPath('data.days', 1);
    }

    public function test_signup_series_counts_students_only(): void
    {
        User::factory()->create(['role' => 'supervisor']);
        User::factory()->create(['role' => 'student']);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/v1/staff/stats/signups?days=7')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_student_cannot_read_stats(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));

        $this->getJson('/api/v1/staff/stats/signups')->assertForbidden();
    }
}
