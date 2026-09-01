<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Resetting a forgotten password, by emailed code.
 *
 * A six-digit code rather than a link: the app is where the password is
 * changed, and a link would bounce the student out to a browser and back. The
 * code is hashed in `password_reset_tokens` exactly as Laravel's own broker
 * stores its tokens, so a leaked database row is not a way in.
 *
 * Whether an address exists is never revealed - the reply is the same either
 * way - because this endpoint is open to anyone.
 */
class PasswordResetController extends Controller
{
    /** How long a code is good for. Long enough to find the email, no longer. */
    private const CODE_MINUTES = 15;

    /**
     * POST /api/auth/forgot-password
     */
    public function request(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = strtolower(trim($validated['email']));
        $user = User::where('email', $email)->first();

        // Same answer for a real address and an invented one.
        $reply = response()->json([
            'message' => 'If that address has an account, a reset code is on its way to it.',
        ], 200);

        if ($user === null) {
            return $reply;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($code), 'created_at' => now()],
        );

        try {
            Mail::raw(
                "Your Fati Market password reset code is {$code}.\n\n"
                    . 'It expires in ' . self::CODE_MINUTES . " minutes.\n"
                    . 'If you did not ask for it, you can ignore this email.',
                function ($message) use ($email) {
                    $message->to($email)->subject('Your Fati Market reset code');
                }
            );
        } catch (\Throwable $e) {
            // The code is already stored, so a mail outage is worth recording
            // but not worth telling the requester about - it would say the
            // address exists.
            Log::error('Failed to send a password reset code', ['error' => $e->getMessage()]);
        }

        return $reply;
    }

    /**
     * POST /api/auth/reset-password
     */
    public function reset(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
            ],
        ], [
            'password.regex' => 'Password must contain at least 8 characters, including uppercase letter, lowercase letter, number, and special character (@$!%*?&).',
        ]);

        $email = strtolower(trim($validated['email']));
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();

        if ($row === null || !Hash::check($validated['code'], $row->token)) {
            return response()->json(['message' => 'That code is not right. Ask for a new one.'], 422);
        }

        if (now()->diffInMinutes($row->created_at) >= self::CODE_MINUTES) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return response()->json(['message' => 'That code has expired. Ask for a new one.'], 422);
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            return response()->json(['message' => 'That code is not right. Ask for a new one.'], 422);
        }

        $user->update(['password' => Hash::make($validated['password'])]);

        // One code, one use - and every existing session goes with it, since a
        // forgotten password is also how a stolen one gets noticed.
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Your password has been changed. Sign in with it now.',
        ], 200);
    }
}
