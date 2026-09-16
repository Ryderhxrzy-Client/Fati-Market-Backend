<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Changing your own password, while signed in.
 *
 * Until this existed the only ways to change a password were to forget it -
 * the emailed code flow - or to go through the personal-email dialog, which
 * demanded a verified second address first and never asked for the password
 * being replaced. Neither is what someone means by "change my password", and
 * an admin had no way at all.
 *
 * The current password is proof that the person at the keyboard is the owner
 * of the account rather than someone who found it signed in, so it is
 * required whenever there is one to give. An account that has never had a
 * usable password - a Google sign-in - has nothing to prove and is setting
 * one for the first time.
 */
class AccountPasswordController extends Controller
{
    /**
     * The same rule the rest of the app applies, so a password that is
     * accepted here is one that can be typed at every sign-in screen.
     */
    private const RULE = [
        'required',
        'string',
        'min:8',
        'confirmed',
        'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
    ];

    private const RULE_MESSAGE = 'Password must contain at least 8 characters, including uppercase letter, lowercase letter, number, and special character (@$!%*?&).';

    /**
     * Whether this account has a password to replace.
     * GET /api/account/password
     */
    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'message' => 'Password status retrieved',
            'data' => [
                'password_set' => $user->password_set_at !== null,
                'requires_current_password' => $user->password_set_at !== null,
            ],
        ], 200);
    }

    /**
     * Replace it.
     * POST /api/account/password
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $hasPassword = $user->password_set_at !== null;

        $validated = $request->validate([
            'current_password' => $hasPassword ? ['required', 'string'] : ['nullable', 'string'],
            'password' => self::RULE,
        ], [
            'password.regex' => self::RULE_MESSAGE,
            'current_password.required' => 'Enter your current password.',
        ]);

        if ($hasPassword && !Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'That is not your current password.',
                'errors' => ['current_password' => ['That is not your current password.']],
            ], 422);
        }

        if (Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Choose a password you are not already using.',
                'errors' => ['password' => ['Choose a password you are not already using.']],
            ], 422);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
            'password_set_at' => now(),
        ]);

        // Every other session is signed out, because a password is usually
        // changed for a reason. The one asking keeps its own token, so the
        // person is not thrown out of the screen they just used.
        $current = $request->user()->currentAccessToken();
        $keepId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        $user->tokens()
            ->when($keepId !== null, fn ($query) => $query->whereKeyNot($keepId))
            ->delete();

        Log::info('Password changed', ['user_id' => $user->user_id]);

        return response()->json([
            'message' => $hasPassword
                ? 'Your password has been changed.'
                : 'Your password has been set.',
            'data' => ['password_set' => true],
        ], 200);
    }
}
