<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;

/**
 * Changing your own password while signed in.
 *
 * The rules that matter: the old password is proof of ownership and cannot be
 * skipped, an account that never had one is setting rather than changing, and
 * a change signs every other device out.
 */
class AccountPasswordTest extends MarketplaceTestCase
{
    private const NEW_PASSWORD = 'Str0ng!pass';

    private function withPassword(User $user, string $password = 'Old!pass123'): User
    {
        $user->forceFill([
            'password' => Hash::make($password),
            'password_set_at' => now(),
        ])->save();

        return $user->fresh();
    }

    #[Test]
    public function the_status_says_whether_there_is_a_password_to_replace(): void
    {
        $student = $this->student();

        // A Google sign-in has an unusable generated hash and no set date.
        $student->forceFill(['password_set_at' => null])->save();

        $this->actingAs($student->fresh())->getJson('/api/account/password')
            ->assertOk()
            ->assertJsonPath('data.password_set', false)
            ->assertJsonPath('data.requires_current_password', false);

        $this->actingAs($this->withPassword($student))->getJson('/api/account/password')
            ->assertOk()
            ->assertJsonPath('data.password_set', true);
    }

    #[Test]
    public function the_current_password_is_required_and_checked(): void
    {
        $student = $this->withPassword($this->student());

        $this->actingAs($student)->postJson('/api/account/password', [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->actingAs($student)->postJson('/api/account/password', [
            'current_password' => 'not-it',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonPath('errors.current_password.0', 'That is not your current password.');

        $this->assertTrue(Hash::check('Old!pass123', $student->fresh()->password));
    }

    #[Test]
    public function the_right_current_password_changes_it(): void
    {
        $student = $this->withPassword($this->student());

        $this->actingAs($student)->postJson('/api/account/password', [
            'current_password' => 'Old!pass123',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk()->assertJsonPath('data.password_set', true);

        $fresh = $student->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertNotNull($fresh->password_set_at);
    }

    #[Test]
    public function an_admin_can_change_their_own_password_too(): void
    {
        $admin = $this->withPassword($this->admin());

        $this->actingAs($admin)->postJson('/api/account/password', [
            'current_password' => 'Old!pass123',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $admin->fresh()->password));
    }

    #[Test]
    public function an_account_that_never_had_a_password_sets_one_without_the_old(): void
    {
        $student = $this->student();
        $student->forceFill(['password' => Hash::make('unusable-random'), 'password_set_at' => null])->save();

        $this->actingAs($student->fresh())->postJson('/api/account/password', [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk()->assertJsonPath('message', 'Your password has been set.');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $student->fresh()->password));
    }

    #[Test]
    public function a_weak_or_unconfirmed_password_is_refused(): void
    {
        $student = $this->withPassword($this->student());

        foreach ([
            ['password' => 'Ab1!c', 'password_confirmation' => 'Ab1!c'],
            ['password' => 'alllowercase1!', 'password_confirmation' => 'alllowercase1!'],
            ['password' => self::NEW_PASSWORD, 'password_confirmation' => 'something-else'],
        ] as $attempt) {
            $this->actingAs($student)
                ->postJson('/api/account/password', array_merge(['current_password' => 'Old!pass123'], $attempt))
                ->assertStatus(422)
                ->assertJsonValidationErrors('password');
        }

        $this->assertTrue(Hash::check('Old!pass123', $student->fresh()->password));
    }

    #[Test]
    public function the_same_password_again_is_refused(): void
    {
        $student = $this->withPassword($this->student(), self::NEW_PASSWORD);

        $this->actingAs($student)->postJson('/api/account/password', [
            'current_password' => self::NEW_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    #[Test]
    public function changing_it_signs_the_other_devices_out(): void
    {
        $student = $this->withPassword($this->student());

        $phone = $student->createToken('phone')->plainTextToken;
        $laptop = $student->createToken('laptop')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $laptop)
            ->postJson('/api/account/password', [
                'current_password' => 'Old!pass123',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertOk();

        // The laptop that made the change is still signed in.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer ' . $laptop)
            ->getJson('/api/account/password')->assertOk();

        // The phone is not. The guard is forgotten first: inside one test the
        // framework keeps the user it resolved a moment ago, which would let
        // a revoked token look alive.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer ' . $phone)
            ->getJson('/api/account/password')->assertStatus(401);
    }

    #[Test]
    public function a_signed_out_visitor_cannot_change_anything(): void
    {
        $this->postJson('/api/account/password', [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(401);
    }
}
