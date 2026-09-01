<?php

namespace Tests\Feature;

use App\Models\StudentVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * Registration by emailed code, which replaced the uploaded student ID.
 *
 * The school address is the credential: only a student holds one, and only its
 * holder can read the code. The document proved less than that - a photograph
 * can be borrowed - and cost an admin a decision on every sign-up.
 */
class EmailVerificationTest extends MarketplaceTestCase
{
    private const EMAIL = 'maria.santos@student.fatima.edu.ph';

    private function signUp(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/register', array_merge([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => self::EMAIL,
            'password' => 'Str0ng!pass',
            'password_confirmation' => 'Str0ng!pass',
        ], $overrides));
    }

    /** Plant a known code the way the mailer would have sent one. */
    private function plantCode(string $email, string $code = '123456'): void
    {
        DB::table('email_verification_codes')->updateOrInsert(
            ['email' => $email],
            ['code' => Hash::make($code), 'attempts' => 0, 'created_at' => now()],
        );
    }

    #[Test]
    public function registering_needs_no_document_and_no_approval(): void
    {
        Mail::fake();

        $this->signUp()
            ->assertStatus(201)
            ->assertJsonPath('data.needs_email_verification', true);

        $user = User::where('email', self::EMAIL)->firstOrFail();

        // Made, but not yet allowed in.
        $this->assertNull($user->email_verified_at);
        $this->assertFalse((bool) $user->is_active);

        // And nothing was filed for an admin to look at.
        $this->assertNull(StudentVerification::where('user_id', $user->user_id)->first());
    }

    #[Test]
    public function an_unverified_account_cannot_sign_in_yet(): void
    {
        Mail::fake();
        $this->signUp()->assertStatus(201);

        $this->postJson('/api/login', [
            'email' => self::EMAIL,
            'password' => 'Str0ng!pass',
        ])
            ->assertStatus(403)
            ->assertJsonPath('needs_email_verification', true);
    }

    #[Test]
    public function the_code_opens_the_account_and_signs_them_straight_in(): void
    {
        Mail::fake();
        $this->signUp()->assertStatus(201);
        $this->plantCode(self::EMAIL);

        $this->postJson('/api/auth/verify-email', [
            'email' => self::EMAIL,
            'code' => '123456',
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user_id', 'role']]);

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue((bool) $user->is_active);

        // The code is spent.
        $this->assertNull(DB::table('email_verification_codes')->where('email', self::EMAIL)->first());
    }

    #[Test]
    public function a_wrong_code_is_refused_and_eventually_burns_itself(): void
    {
        Mail::fake();
        $this->signUp()->assertStatus(201);
        $this->plantCode(self::EMAIL);

        // Six digits is a small space, so guessing is capped.
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/verify-email', [
                'email' => self::EMAIL,
                'code' => '000000',
            ])->assertStatus(422);
        }

        // Even the right code is dead now.
        $this->postJson('/api/auth/verify-email', [
            'email' => self::EMAIL,
            'code' => '123456',
        ])->assertStatus(422);

        $this->assertNull(User::where('email', self::EMAIL)->firstOrFail()->email_verified_at);
    }

    #[Test]
    public function only_school_addresses_may_register(): void
    {
        Mail::fake();

        $this->signUp(['email' => 'maria@gmail.com'])->assertStatus(422);
    }

    #[Test]
    public function resending_says_nothing_about_who_is_registered(): void
    {
        Mail::fake();
        $this->signUp()->assertStatus(201);

        $known = $this->postJson('/api/auth/resend-code', ['email' => self::EMAIL]);
        $unknown = $this->postJson('/api/auth/resend-code', [
            'email' => 'nobody@student.fatima.edu.ph',
        ]);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    #[Test]
    public function an_account_approved_the_old_way_still_signs_in(): void
    {
        // The upgrade must not lock out a single student who was approved when
        // registration still meant photographing a card.
        $student = $this->student();
        $student->update([
            'email' => 'legacy@student.fatima.edu.ph',
            'password' => Hash::make('Str0ng!pass'),
            'email_verified_at' => null,
        ]);

        StudentVerification::create([
            'user_id' => $student->user_id,
            'verification_use' => 'student_id',
            'link' => 'https://cdn.example.test/id.jpg',
            'is_verified' => true,
            'status' => 'approved',
        ]);

        $this->postJson('/api/login', [
            'email' => 'legacy@student.fatima.edu.ph',
            'password' => 'Str0ng!pass',
        ])->assertOk();
    }
}
