<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'أحمد',
            'father_name' => 'محمد',
            'last_name' => 'عيسى',
            'email' => 'student@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'year' => 1,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.role', 'student')
            ->assertJsonPath('data.user.full_name', 'أحمد محمد عيسى')
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_registration_requires_all_three_name_parts(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'أحمد',
            'email' => 'student@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'year' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['father_name', 'last_name']);
    }

    public function test_registration_rejects_the_removed_student_id_silently(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'أحمد',
            'father_name' => 'محمد',
            'last_name' => 'عيسى',
            'student_id' => '20260001',
            'email' => 'student@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'year' => 1,
        ]);

        $response->assertCreated();
        $this->assertArrayNotHasKey('student_id', $response->json('data.user'));
    }
}
