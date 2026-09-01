<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerification;
use App\Services\GoogleIdentity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Linking the address a student keeps after they graduate.
 *
 * A school account is lent. The day it is disabled, an account keyed only to it
 * is unreachable and everything behind it - points, orders, listings - is gone
 * with it. This is the way back in, and because it IS a way in, it is treated
 * like one: proven by a code before it counts for anything, and proven again on
 * every change.
 *
 * That second part matters more than it looks. Without it, anyone holding an
 * open session - a borrowed phone, a device left signed in - could point the
 * recovery address at themselves and take the account for good. So the code
 * always goes to the NEW address, and the change lands only when it comes back.
 */
class PersonalEmailController extends Controller
{
    public function __construct(private readonly EmailVerification $verification)
    {
    }

    /**
     * Start linking or changing it.
     * POST /api/account/personal-email
     */
    public function request(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'personal_email' => [
                'required',
                'email',
                // Not somebody else's way in, and not a second copy of a
                // primary address either.
                Rule::unique('users', 'personal_email')->ignore($user->user_id, 'user_id'),
                Rule::unique('users', 'email'),
            ],
        ]);

        $address = strtolower(trim($validated['personal_email']));

        // A school address is already the primary one and disappears with the
        // account, so linking it as the backup would protect nothing.
        if (str_ends_with($address, GoogleIdentity::REQUIRED_DOMAIN)) {
            return response()->json([
                'message' => 'Use a personal address - a school one stops working when you graduate.',
            ], 422);
        }

        $this->verification->send($address, $user->studentInfo?->first_name ?? 'there');

        return response()->json([
            'message' => "We sent a 6-digit code to {$address}. Enter it to finish linking.",
            'data' => ['personal_email' => $address],
        ], 200);
    }

    /**
     * Finish it with the code that was sent.
     * POST /api/account/personal-email/confirm
     */
    public function confirm(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'personal_email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        $address = strtolower(trim($validated['personal_email']));

        try {
            // Deliberately not the account-opening confirm(): this proves an
            // address without touching who is verified to sign in.
            $this->verification->confirmAddress($address, $validated['code']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Re-checked here as well as at request time: minutes passed in
        // between, and someone else may have linked it meanwhile.
        $taken = User::where('user_id', '!=', $user->user_id)
            ->where(fn ($q) => $q->where('personal_email', $address)->orWhere('email', $address))
            ->exists();

        if ($taken) {
            return response()->json([
                'message' => 'That address now belongs to another account.',
            ], 409);
        }

        $user->update([
            'personal_email' => $address,
            'personal_email_verified_at' => now(),
        ]);

        return response()->json([
            'message' => "{$address} is linked. You can sign in with it if you lose your school account.",
            'data' => [
                'personal_email' => $address,
                'personal_email_verified_at' => $user->fresh()->personal_email_verified_at,
            ],
        ], 200);
    }

    /**
     * Unlink it.
     * DELETE /api/account/personal-email
     *
     * Allowed, but it is the student giving up their own way back in, so the
     * reply says so rather than confirming silently.
     */
    public function destroy(Request $request)
    {
        $request->user()->update([
            'personal_email' => null,
            'personal_email_verified_at' => null,
        ]);

        return response()->json([
            'message' => 'Removed. Without it you will lose access to this account when your school email stops working.',
        ], 200);
    }
}
