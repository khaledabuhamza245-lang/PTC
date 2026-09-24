<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStudent(string $password = 'Student@12345'): array
    {
        $user = User::factory()->create(['password' => $password]);
        $token = $user->createToken('web')->plainTextToken;

        return [$user, $token];
    }

    public function test_student_changes_password_with_the_current_one(): void
    {
        [$user, $token] = $this->actingAsStudent();

        $this->withToken($token)->postJson('/api/v1/me/password', [
            'current_password' => 'Student@12345',
            'password' => 'NewPass@6789',
            'password_confirmation' => 'NewPass@6789',
        ])->assertOk()
            ->assertJsonPath('message', 'تم تغيير كلمة السر.');

        $this->assertTrue(Hash::check('NewPass@6789', $user->fresh()->password));
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        [$user, $token] = $this->actingAsStudent();

        $this->withToken($token)->postJson('/api/v1/me/password', [
            'current_password' => 'NotMyPassword@1',
            'password' => 'NewPass@6789',
            'password_confirmation' => 'NewPass@6789',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('Student@12345', $user->fresh()->password));
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        [$user, $token] = $this->actingAsStudent();

        $this->withToken($token)->postJson('/api/v1/me/password', [
            'current_password' => 'Student@12345',
            'password' => 'NewPass@6789',
            'password_confirmation' => 'NewPass@6780',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('Student@12345', $user->fresh()->password));
    }

    public function test_new_password_must_differ_from_the_current_one(): void
    {
        [, $token] = $this->actingAsStudent();

        $this->withToken($token)->postJson('/api/v1/me/password', [
            'current_password' => 'Student@12345',
            'password' => 'Student@12345',
            'password_confirmation' => 'Student@12345',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    /**
     * القرار المعماري في ProfileController::updatePassword محروسًا:
     * تُلغى الجلسات الأخرى ويبقى الجهاز الذي غُيّرت منه كلمة السر.
     */
    public function test_other_sessions_are_revoked_and_the_current_one_survives(): void
    {
        [$user, $token] = $this->actingAsStudent();

        $phone = $user->createToken('phone')->plainTextToken;
        $tablet = $user->createToken('tablet')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/me/password', [
            'current_password' => 'Student@12345',
            'password' => 'NewPass@6789',
            'password_confirmation' => 'NewPass@6789',
        ])->assertOk()
            ->assertJsonPath('revoked_sessions', 2);

        $this->assertNull(PersonalAccessToken::findToken($phone));
        $this->assertNull(PersonalAccessToken::findToken($tablet));
        $this->assertNotNull(PersonalAccessToken::findToken($token));

        // والتوكن الحالي ما زال يفتح المسارات فعلًا، لا موجودًا في الجدول فقط.
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    }

    public function test_guests_cannot_change_a_password(): void
    {
        $this->postJson('/api/v1/me/password', [
            'current_password' => 'Student@12345',
            'password' => 'NewPass@6789',
            'password_confirmation' => 'NewPass@6789',
        ])->assertStatus(401);
    }
}
