<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Proving an email address with a one-time code.
 *
 * This is what replaced the student ID upload. The reasoning is that the school
 * domain is the credential: only a student has an `@student.fatima.edu.ph`
 * address, and only the holder of that address can read what is sent to it. A
 * photograph of a card proved less - a photograph can be borrowed - and cost an
 * admin a decision on every single sign-up.
 *
 * The code is hashed at rest and limited in tries, because six digits is a
 * small space to guess through.
 */
class EmailVerification
{
    /** Long enough to switch apps and read an email, short enough to matter. */
    public const CODE_MINUTES = 15;

    /** Guesses allowed before the code is burned. */
    private const MAX_ATTEMPTS = 6;

    private const TABLE = 'email_verification_codes';

    /** Issue a fresh code and email it. Any previous one stops working. */
    public function send(string $email, string $firstName = 'there'): void
    {
        $email = strtolower(trim($email));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table(self::TABLE)->updateOrInsert(
            ['email' => $email],
            ['code' => Hash::make($code), 'attempts' => 0, 'created_at' => now()],
        );

        try {
            Mail::raw(
                "Hi {$firstName},\n\n"
                    . "Your Fati Market verification code is {$code}.\n\n"
                    . 'It expires in ' . self::CODE_MINUTES . " minutes.\n"
                    . "If you did not sign up, you can ignore this email.",
                function ($message) use ($email) {
                    $message->to($email)->subject('Your Fati Market verification code');
                }
            );
        } catch (\Throwable $e) {
            // The code is already stored, so a mail outage is a delivery
            // problem rather than a failed registration - it is logged, and
            // the student can ask for another one.
            Log::error('Failed to send a verification code', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Spend a code and open the account it belongs to.
     *
     * @throws RuntimeException with wording meant for the person typing.
     */
    public function confirm(string $email, string $code): void
    {
        $this->confirmAddress($email, $code);

        User::where('email', strtolower(trim($email)))->update([
            'email_verified_at' => now(),
            // Verified is all it takes now: no queue, no approval.
            'is_active' => true,
        ]);
    }

    /**
     * Spend a code, and nothing more.
     *
     * Proving an address is not the same act as opening an account: a personal
     * address is proven by the same code but must not touch who is allowed to
     * sign in, or linking one would quietly verify an account that never was.
     *
     * @throws RuntimeException with wording meant for the person typing.
     */
    public function confirmAddress(string $email, string $code): void
    {
        $email = strtolower(trim($email));
        $row = DB::table(self::TABLE)->where('email', $email)->first();

        if ($row === null) {
            throw new RuntimeException('Ask for a code first.');
        }

        if (now()->diffInMinutes($row->created_at) >= self::CODE_MINUTES) {
            DB::table(self::TABLE)->where('email', $email)->delete();

            throw new RuntimeException('That code has expired. Ask for a new one.');
        }

        if ($row->attempts >= self::MAX_ATTEMPTS) {
            DB::table(self::TABLE)->where('email', $email)->delete();

            throw new RuntimeException('Too many wrong tries. Ask for a new code.');
        }

        if (!Hash::check($code, $row->code)) {
            DB::table(self::TABLE)->where('email', $email)->increment('attempts');

            throw new RuntimeException('That code is not right.');
        }

        DB::table(self::TABLE)->where('email', $email)->delete();
    }

    /**
     * Whether this account may sign in.
     *
     * Accounts that predate this system were approved by an admin looking at a
     * document, and they must keep working - locking out every existing student
     * to introduce a new check would be the worst possible upgrade.
     */
    public function isVerified(User $user): bool
    {
        if ($user->email_verified_at !== null) {
            return true;
        }

        return $user->verification !== null && (bool) $user->verification->is_verified;
    }
}
