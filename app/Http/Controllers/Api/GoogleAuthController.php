<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentInformation;
use App\Models\User;
use App\Services\GoogleIdentity;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Signing in with Google, on the same terms as everything else.
 *
 * What the marketplace needs to know is that an address belongs to the school
 * and to the person holding it. An email registration proves that with a code;
 * Google proves the same thing by having verified the address already, and the
 * identity check refuses anything outside the school domain. So a Google
 * sign-up completes on the spot - there is no code worth sending to an address
 * Google has just vouched for.
 */
class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly GoogleIdentity $google,
        private readonly \App\Services\EmailVerification $verification,
    ) {
    }

    /**
     * Sign in an existing account.
     * POST /api/auth/google
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        try {
            $identity = $this->google->verify($validated['id_token']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $user = User::where('email', $identity['email'])->first();

        if ($user === null) {
            // Registering creates records, so it does not happen on the sign-in
            // route. The app reads this code and offers to sign them up.
            return response()->json([
                'message' => 'No account here yet - signing up takes one more tap.',
                'needs_registration' => true,
                'email' => $identity['email'],
            ], 404);
        }

        if ($user->role === User::ROLE_STUDENT && !$this->verification->isVerified($user)) {
            return response()->json([
                'message' => 'Verify your email first - we can send you a new code.',
                'needs_email_verification' => true,
                'email' => $user->email,
            ], 403);
        }

        $user->update(['is_active' => true]);

        return response()->json([
            'message' => 'Login successful',
            'data' => $this->session($user),
        ], 200);
    }

    /**
     * Register with Google.
     * POST /api/auth/google/register
     *
     * Nothing to upload and nobody to wait for: Google has verified a school
     * address, which is the whole of what registration establishes.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string'],
            'profile_picture' => ['nullable', 'image', 'max:5120', 'mimes:jpg,jpeg,png'],
        ]);

        try {
            $identity = $this->google->verify($validated['id_token']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (User::where('email', $identity['email'])->exists()) {
            return response()->json([
                'message' => 'An account already uses that email. Sign in instead.',
            ], 409);
        }

        try {
            // Google already has a picture of them; uploading another is
            // optional rather than a second thing to find.
            $profileUrl = $request->hasFile('profile_picture')
                ? $this->upload($request->file('profile_picture'), 'student_profiles')
                : $identity['picture'];

            $result = DB::transaction(function () use ($identity, $profileUrl) {
                $user = User::create([
                    'email' => $identity['email'],
                    // Google is the credential. A random password keeps the
                    // column non-null without ever being a way in - and the
                    // reset flow can give them one later if they want it.
                    'password' => Hash::make(Str::random(48)),
                    'wallet_points' => 0,
                    'role' => User::ROLE_STUDENT,
                    // Google has already proven the address, and it is a school
                    // address or the identity check above would have refused it.
                    // There is nothing left to verify, so the account opens now.
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);

                $info = StudentInformation::create([
                    'user_id' => $user->user_id,
                    'first_name' => $identity['first_name'],
                    'last_name' => $identity['last_name'],
                    'profile_picture' => $profileUrl,
                ]);

                return ['user_id' => $user->user_id, 'student_id' => $info->student_id];
            });

            // Signed in already - there is no waiting room to send them to.
            return response()->json([
                'message' => 'Welcome to Fati Market.',
                'data' => $this->session(User::where('email', $identity['email'])->firstOrFail()),
            ], 201);

        } catch (\Throwable $e) {
            Log::error('Google registration failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** The payload the app stores after any successful sign-in. */
    private function session(User $user): array
    {
        $info = StudentInformation::where('user_id', $user->user_id)->first();

        $lifetimeMinutes = (int) config('sanctum.expiration');
        $expiresAt = $lifetimeMinutes > 0 ? now()->addMinutes($lifetimeMinutes) : null;

        return [
            'token' => $user->createToken('auth_token', ['*'], $expiresAt)->plainTextToken,
            'user_id' => $user->user_id,
            'email' => $user->email,
            'role' => $user->role,
            'first_name' => $info?->first_name,
            'last_name' => $info?->last_name,
            'profile_picture' => $info?->profile_picture,
            'wallet_points' => $user->wallet_points,
        ];
    }

    /** @throws RuntimeException when Cloudinary gives nothing back. */
    private function upload($file, string $folder): string
    {
        $cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => config('services.cloudinary.cloud_name'),
                'api_key' => config('services.cloudinary.key'),
                'api_secret' => config('services.cloudinary.secret'),
            ],
        ]);

        $result = $cloudinary->uploadApi()->upload($file->getRealPath(), [
            'folder' => $folder,
            'resource_type' => 'image',
        ]);

        if (!isset($result['secure_url'])) {
            throw new RuntimeException('Failed to upload the photo.');
        }

        return $result['secure_url'];
    }
}
