<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_open_staff_dashboard(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/staff/dashboard')->assertForbidden();
    }

    public function test_supervisor_can_open_staff_dashboard(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'supervisor']));

        $this->getJson('/api/v1/staff/dashboard')->assertOk();
    }
}
