<?php

namespace Tests\Feature;

use App\Models\StudentVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * A login is meant to keep the user signed in for a week and then stop
 * working, so the app never has to ask for a password mid-semester and a
 * stolen phone cannot stay authenticated forever.
 */
class LoginSessionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedStudent(): User
    {
        $user = User::factory()->create();

        StudentVerification::create([
            'user_id' => $user->user_id,
            'verification_use' => 'registration',
            'is_verified' => true,
            'status' => 'approved',
        ]);

        return $user;
    }

    private function login(User $user)
    {
        return $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
    }

    public function test_login_issues_a_seven_day_token(): void
    {
        $this->assertSame(60 * 24 * 7, (int) config('sanctum.expiration'));

        $user = $this->verifiedStudent();

        $response = $this->login($user)->assertOk();

        $response->assertJsonPath('data.expires_in', 60 * 24 * 7 * 60);
        $this->assertNotNull($response->json('data.expires_at'));

        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertNotNull($token);
        $this->assertNotNull($token->expires_at);
        $this->assertEqualsWithDelta(
            now()->addDays(7)->timestamp,
            $token->expires_at->timestamp,
            5,
        );
    }

    public function test_token_still_authenticates_just_under_seven_days(): void
    {
        $user = $this->verifiedStudent();
        $token = $this->login($user)->json('data.token');

        $this->travelTo(now()->addDays(7)->subMinutes(5));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/wallet')
            ->assertOk();
    }

    public function test_token_is_rejected_once_seven_days_have_passed(): void
    {
        $user = $this->verifiedStudent();
        $token = $this->login($user)->json('data.token');

        $this->travelTo(now()->addDays(7)->addMinutes(5));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/wallet')
            ->assertUnauthorized();
    }
}
