<?php

namespace Tests\Feature;

use App\Models\StudentVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two ways in that did not exist: Google, and a forgotten password.
 *
 * Google is faked at the boundary - the tokeninfo call - because the point
 * being tested is what this app does with a verified identity, not whether
 * Google can verify one.
 */
class AuthOptionsTest extends MarketplaceTestCase
{
    private const CLIENT_ID = '1234567890-test.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => self::CLIENT_ID]);
    }

    /** Pretend Google verified a token for this address. */
    private function googleReturns(array $overrides = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(array_merge([
                'aud' => self::CLIENT_ID,
                'email' => 'juan.cruz@student.fatima.edu.ph',
                'email_verified' => 'true',
                'given_name' => 'Juan',
                'family_name' => 'Cruz',
                'picture' => 'https://lh3.googleusercontent.com/x',
            ], $overrides), 200),
        ]);
    }

    // ── Google ───────────────────────────────────────────────────────────

    #[Test]
    public function a_token_minted_for_another_app_is_refused(): void
    {
        $this->googleReturns(['aud' => 'someone-elses-client.apps.googleusercontent.com']);

        $this->postJson('/api/auth/google', ['id_token' => 'anything'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That Google sign-in was not issued for this app.');
    }

    #[Test]
    public function a_personal_google_account_is_refused(): void
    {
        $this->googleReturns(['email' => 'juan@gmail.com']);

        $this->postJson('/api/auth/google', ['id_token' => 'anything'])
            ->assertStatus(422);
    }

    #[Test]
    public function an_unverified_google_address_is_refused(): void
    {
        $this->googleReturns(['email_verified' => 'false']);

        $this->postJson('/api/auth/google', ['id_token' => 'anything'])
            ->assertStatus(422);
    }

    #[Test]
    public function signing_in_with_google_before_registering_is_sent_to_sign_up(): void
    {
        $this->googleReturns();

        $this->postJson('/api/auth/google', ['id_token' => 'anything'])
            ->assertStatus(404)
            ->assertJsonPath('needs_registration', true);
    }

    #[Test]
    public function google_cannot_get_past_the_unverified_email_wall(): void
    {
        $this->googleReturns();

        // The account exists but its address was never proven - a half-finished
        // email sign-up, abandoned before the code came back.
        $student = $this->student();
        $student->update([
            'email' => 'juan.cruz@student.fatima.edu.ph',
            'email_verified_at' => null,
        ]);

        $this->postJson('/api/auth/google', ['id_token' => 'anything'])
            ->assertStatus(403)
            ->assertJsonPath('needs_email_verification', true);
    }

    #[Test]
    public function a_verified_student_signs_in_with_google_and_gets_a_token(): void
    {
        $this->googleReturns();

        $student = $this->student();
        $student->update([
            'email' => 'juan.cruz@student.fatima.edu.ph',
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/auth/google', ['id_token' => 'anything'])
            ->assertOk()
            ->assertJsonPath('data.email', 'juan.cruz@student.fatima.edu.ph')
            ->assertJsonStructure(['data' => ['token', 'user_id', 'role']]);
    }

    #[Test]
    public function google_sign_in_is_refused_while_the_server_has_no_client_id(): void
    {
        config(['services.google.client_id' => null]);

        $this->postJson('/api/auth/google', ['id_token' => 'anything'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Google sign-in is not configured on the server yet.');
    }

    // ── Forgotten password ───────────────────────────────────────────────

    #[Test]
    public function asking_for_a_code_says_nothing_about_whether_the_account_exists(): void
    {
        Mail::fake();

        $known = $this->student()->email;

        $first = $this->postJson('/api/auth/forgot-password', ['email' => $known]);
        $second = $this->postJson('/api/auth/forgot-password', [
            'email' => 'nobody@student.fatima.edu.ph',
        ]);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('message'), $second->json('message'));

        // Only the real one is actually written a code.
        $this->assertNotNull(DB::table('password_reset_tokens')->where('email', $known)->first());
        $this->assertNull(
            DB::table('password_reset_tokens')->where('email', 'nobody@student.fatima.edu.ph')->first()
        );
    }

    #[Test]
    public function the_emailed_code_changes_the_password_once(): void
    {
        Mail::fake();

        $student = $this->student();

        $this->postJson('/api/auth/forgot-password', ['email' => $student->email])->assertOk();

        // The stored code is hashed, so the test plants a known one the same
        // way the endpoint would.
        DB::table('password_reset_tokens')->where('email', $student->email)->update([
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'email' => $student->email,
            'code' => '123456',
            'password' => 'Str0ng!pass',
            'password_confirmation' => 'Str0ng!pass',
        ])->assertOk();

        $this->assertTrue(Hash::check('Str0ng!pass', $student->fresh()->password));

        // The code is spent, so a replay fails.
        $this->postJson('/api/auth/reset-password', [
            'email' => $student->email,
            'code' => '123456',
            'password' => 'An0ther!pass',
            'password_confirmation' => 'An0ther!pass',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_wrong_code_changes_nothing(): void
    {
        Mail::fake();

        $student = $this->student();
        $was = $student->password;

        $this->postJson('/api/auth/forgot-password', ['email' => $student->email])->assertOk();

        $this->postJson('/api/auth/reset-password', [
            'email' => $student->email,
            'code' => '000000',
            'password' => 'Str0ng!pass',
            'password_confirmation' => 'Str0ng!pass',
        ])->assertStatus(422);

        $this->assertSame($was, $student->fresh()->password);
    }
}
