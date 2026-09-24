<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * من يرى دليل المستخدمين.
 *
 * المشرف يحتاج رؤية الطلاب — هذا جوهر عمله. لكنه كان يرى كل السجلات
 * بما فيها حسابات المدراء وبُردهم، لأن /staff/users و /admin/users
 * يشتركان في نفس المعالج بلا أي تقييد حسب دور المستدعي.
 */
class UserDirectoryAccessTest extends TestCase
{
    use RefreshDatabase;

    private function seedUsers(): void
    {
        User::factory()->create(['role' => 'student', 'first_name' => 'طالب', 'last_name' => 'أول']);
        User::factory()->create(['role' => 'student', 'first_name' => 'طالب', 'last_name' => 'ثانٍ']);
        User::factory()->create(['role' => 'supervisor', 'first_name' => 'مشرف', 'last_name' => 'آخر']);
        User::factory()->create(['role' => 'admin', 'first_name' => 'مدير', 'last_name' => 'آخر']);
    }

    public function test_admin_can_search_by_full_name_across_columns(): void
    {
        User::factory()->create([
            'role' => 'student',
            'first_name' => 'أحمد',
            'father_name' => 'محمد',
            'last_name' => 'عيسى',
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        // البحث بكلمتين من عمودين مختلفين — كان يعمل أيام عمود full_name الواحد.
        $this->getJson('/api/v1/admin/users?search='.urlencode('أحمد عيسى'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'أحمد محمد عيسى');
    }

    public function test_supervisor_sees_students(): void
    {
        $this->seedUsers();
        Sanctum::actingAs(User::factory()->create(['role' => 'supervisor']));

        $roles = collect($this->getJson('/api/v1/staff/users')->assertOk()->json('data'))
            ->pluck('role');

        $this->assertContains('student', $roles->all(), 'المشرف يجب أن يرى الطلاب');
        $this->assertSame(2, $roles->filter(fn ($r) => $r === 'student')->count());
    }

    public function test_supervisor_does_not_see_admin_accounts(): void
    {
        $this->seedUsers();
        Sanctum::actingAs(User::factory()->create(['role' => 'supervisor']));

        $roles = collect($this->getJson('/api/v1/staff/users')->assertOk()->json('data'))
            ->pluck('role')->unique()->values()->all();

        $this->assertSame(['student'], $roles,
            'المشرف يجب ألا يرى حسابات المدراء ولا المشرفين الآخرين');
    }

    public function test_supervisor_cannot_reach_admin_accounts_via_role_filter(): void
    {
        $this->seedUsers();
        Sanctum::actingAs(User::factory()->create(['role' => 'supervisor']));

        $this->getJson('/api/v1/staff/users?role=admin')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_admin_sees_every_role(): void
    {
        $this->seedUsers();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $roles = collect($this->getJson('/api/v1/staff/users')->assertOk()->json('data'))
            ->pluck('role')->unique()->sort()->values()->all();

        $this->assertSame(['admin', 'student', 'supervisor'], $roles);
    }

    public function test_student_is_denied_entirely(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));

        $this->getJson('/api/v1/staff/users')->assertForbidden();
    }
}
