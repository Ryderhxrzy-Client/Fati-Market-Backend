<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * The address a student keeps after they graduate.
 *
 * A school account is lent. Once it is disabled, an account keyed only to it is
 * unreachable, and the points and order history behind it go with it. This is
 * the way back in - and because it is a way in, it is guarded like one.
 */
class PersonalEmailTest extends MarketplaceTestCase
{
    private const PERSONAL = 'maria.santos@gmail.com';

    /** Plant a known code the way the mailer would have sent one. */
    private function plantCode(string $email, string $code = '123456'): void
    {
        DB::table('email_verification_codes')->updateOrInsert(
            ['email' => strtolower($email)],
            ['code' => Hash::make($code), 'attempts' => 0, 'created_at' => now()],
        );
    }

    private function link(User $user, string $address = self::PERSONAL): void
    {
        $this->actingAs($user)
            ->postJson('/api/account/personal-email', ['personal_email' => $address])
            ->assertOk();

        $this->plantCode($address);

        $this->actingAs($user)
            ->postJson('/api/account/personal-email/confirm', [
                'personal_email' => $address,
                'code' => '123456',
            ])->assertOk();
    }

    #[Test]
    public function linking_takes_a_code_sent_to_the_new_address(): void
    {
        Mail::fake();

        $student = $this->student();
        $this->link($student);

        $fresh = $student->fresh();
        $this->assertSame(self::PERSONAL, $fresh->personal_email);
        $this->assertNotNull($fresh->personal_email_verified_at);
    }

    #[Test]
    public function an_unconfirmed_address_counts_for_nothing(): void
    {
        Mail::fake();

        $student = $this->student();

        // Asked for, never confirmed.
        $this->actingAs($student)
            ->postJson('/api/account/personal-email', ['personal_email' => self::PERSONAL])
            ->assertOk();

        $this->assertNull($student->fresh()->personal_email);
    }

    #[Test]
    public function changing_it_needs_a_code_at_the_new_address(): void
    {
        // The whole point: someone holding an open session must not be able to
        // move the recovery address to one they own.
        Mail::fake();

        $student = $this->student();
        $this->link($student);

        $this->actingAs($student)
            ->postJson('/api/account/personal-email', ['personal_email' => 'thief@gmail.com'])
            ->assertOk();

        // No code, no change - the old address still stands.
        $this->actingAs($student)
            ->postJson('/api/account/personal-email/confirm', [
                'personal_email' => 'thief@gmail.com',
                'code' => '000000',
            ])->assertStatus(422);

        $this->assertSame(self::PERSONAL, $student->fresh()->personal_email);
    }

    #[Test]
    public function a_school_address_is_refused_as_the_backup(): void
    {
        Mail::fake();

        $this->actingAs($this->student())
            ->postJson('/api/account/personal-email', [
                'personal_email' => 'someone@student.fatima.edu.ph',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function two_accounts_cannot_share_one_personal_address(): void
    {
        Mail::fake();

        $first = $this->student();
        $this->link($first);

        $this->actingAs($this->student())
            ->postJson('/api/account/personal-email', ['personal_email' => self::PERSONAL])
            ->assertStatus(422);
    }

    #[Test]
    public function it_cannot_be_somebody_elses_school_address(): void
    {
        Mail::fake();

        $other = $this->student();

        $this->actingAs($this->student())
            ->postJson('/api/account/personal-email', ['personal_email' => $other->email])
            ->assertStatus(422);
    }

    // ── What it buys the alumni ──────────────────────────────────────────

    #[Test]
    public function the_linked_address_signs_in_after_graduation(): void
    {
        Mail::fake();

        $student = $this->student();
        $student->update(['password' => Hash::make('Str0ng!pass')]);
        $this->link($student);

        // The school account is gone; this is all they have left.
        $this->postJson('/api/login', [
            'email' => self::PERSONAL,
            'password' => 'Str0ng!pass',
        ])
            ->assertOk()
            ->assertJsonPath('data.personal_email', self::PERSONAL);
    }

    #[Test]
    public function an_unlinked_address_opens_nothing(): void
    {
        $student = $this->student();
        $student->update([
            'password' => Hash::make('Str0ng!pass'),
            // Set by hand, never proven - a guess at someone's recovery
            // address must not be a way in.
            'personal_email' => self::PERSONAL,
            'personal_email_verified_at' => null,
        ]);

        $this->postJson('/api/login', [
            'email' => self::PERSONAL,
            'password' => 'Str0ng!pass',
        ])->assertStatus(401);
    }

    #[Test]
    public function a_password_can_be_set_through_the_personal_address(): void
    {
        // A Google sign-up has no password at all, so this is how an alumni
        // gets their first one - the only address still reaching them.
        Mail::fake();

        $student = $this->student();
        $this->link($student);

        $this->postJson('/api/auth/forgot-password', ['email' => self::PERSONAL])->assertOk();

        DB::table('password_reset_tokens')->where('email', self::PERSONAL)->update([
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'email' => self::PERSONAL,
            'code' => '123456',
            'password' => 'Br4ndNew!pass',
            'password_confirmation' => 'Br4ndNew!pass',
        ])->assertOk();

        $this->postJson('/api/login', [
            'email' => self::PERSONAL,
            'password' => 'Br4ndNew!pass',
        ])->assertOk();
    }
}
