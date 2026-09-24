<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_save_course_progress(): void
    {
        $user = User::factory()->create();
        $course = Course::create([
            'key' => 'c_TEST100',
            'code' => 'TEST 100',
            'name_ar' => 'مساق تجريبي',
            'name_en' => 'Test Course',
            'year' => 1,
            'semester' => 1,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/progress/'.$course->key, [
            'data' => ['status' => 'studying', 'hours' => 4],
        ])->assertOk()->assertJsonPath('data.data.hours', 4);
    }
}
