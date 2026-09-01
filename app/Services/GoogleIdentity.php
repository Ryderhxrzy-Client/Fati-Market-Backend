<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Turns a Google ID token into the person it names.
 *
 * Verification is delegated to Google's own tokeninfo endpoint rather than
 * checking the JWT signature here. That keeps the key rotation, the algorithm
 * choice and the expiry rules on Google's side, and costs one HTTPS call on a
 * screen the user is already waiting on. What this class still has to enforce
 * is everything Google cannot know about us: that the token was minted for THIS
 * app, that the address is verified, and that it belongs to the school.
 */
class GoogleIdentity
{
    private const TOKENINFO = 'https://oauth2.googleapis.com/tokeninfo';

    /** Only school accounts may hold a marketplace account, however they sign in. */
    public const REQUIRED_DOMAIN = '@student.fatima.edu.ph';

    /**
     * @return array{email: string, first_name: string, last_name: string, picture: ?string}
     *
     * @throws RuntimeException with a message meant for the user.
     */
    public function verify(string $idToken): array
    {
        $clientId = config('services.google.client_id');

        if (blank($clientId)) {
            // A misconfigured server must not silently accept anything.
            throw new RuntimeException('Google sign-in is not configured on the server yet.');
        }

        $response = Http::timeout(15)->get(self::TOKENINFO, ['id_token' => $idToken]);

        if (!$response->successful()) {
            Log::warning('Google rejected an ID token', ['status' => $response->status()]);

            throw new RuntimeException('That Google sign-in could not be verified. Try again.');
        }

        $claims = $response->json();

        // Whose app the token was minted for. Without this check any Google
        // token from any app would be accepted as one of ours.
        $audience = $claims['aud'] ?? null;

        if ($audience !== $clientId) {
            Log::warning('Google ID token was issued for another app', ['aud' => $audience]);

            throw new RuntimeException('That Google sign-in was not issued for this app.');
        }

        $email = strtolower(trim($claims['email'] ?? ''));

        if ($email === '') {
            throw new RuntimeException('That Google account has no email address.');
        }

        // Google says whether it has proven the address belongs to the holder.
        // The string form is what tokeninfo returns.
        $verified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$verified) {
            throw new RuntimeException('That Google account has an unverified email address.');
        }

        if (!str_ends_with($email, self::REQUIRED_DOMAIN)) {
            throw new RuntimeException(
                'Use your school account - it must end with ' . self::REQUIRED_DOMAIN . '.'
            );
        }

        return [
            'email' => $email,
            'first_name' => trim($claims['given_name'] ?? '') ?: 'Student',
            'last_name' => trim($claims['family_name'] ?? '') ?: '',
            'picture' => $claims['picture'] ?? null,
        ];
    }
}
