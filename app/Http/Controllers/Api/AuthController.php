<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentInformation;
use App\Models\StudentVerification;
use App\Models\User;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private readonly \App\Services\EmailVerification $verification)
    {
    }

    /**
     * Register with an email address.
     * POST /api/register
     *
     * No document, no approval queue. The school address IS the credential -
     * only a student has one, and only its holder can read the code sent to it,
     * which is more than a photograph of a card ever proved. The account exists
     * immediately but cannot sign in until the code comes back.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users', 'ends_with:@student.fatima.edu.ph'],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
            ],
            // Optional now, and the only file left anywhere in registration.
            'profile_picture' => ['nullable', 'image', 'max:5120', 'mimes:jpg,jpeg,png'],
        ], [
            'password.regex' => 'Password must contain at least 8 characters, including uppercase letter, lowercase letter, number, and special character (@$!%*?&).',
        ]);

        try {
            $profilePictureUrl = $request->hasFile('profile_picture')
                ? $this->uploadImage($request->file('profile_picture'), 'student_profiles')
                : null;

            $result = DB::transaction(function () use ($validated, $profilePictureUrl) {
                $user = User::create([
                    'email' => strtolower(trim($validated['email'])),
                    'password' => Hash::make($validated['password']),
                    'wallet_points' => 0,
                    'role' => User::ROLE_STUDENT,
                    // Both flip together the moment the code comes back.
                    'is_active' => false,
                    'email_verified_at' => null,
                ]);

                $info = StudentInformation::create([
                    'user_id' => $user->user_id,
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'profile_picture' => $profilePictureUrl,
                ]);

                return ['user_id' => $user->user_id, 'student_id' => $info->student_id];
            });

            $this->verification->send($validated['email'], $validated['first_name']);

            return response()->json([
                'message' => 'Almost there - we sent a 6-digit code to your school email.',
                'data' => [
                    'user_id' => $result['user_id'],
                    'student_id' => $result['student_id'],
                    'email' => $validated['email'],
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'needs_email_verification' => true,
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Confirm the emailed code and open the account.
     * POST /api/auth/verify-email
     */
    public function verifyEmail(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        try {
            $this->verification->confirm($validated['email'], $validated['code']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $user = User::where('email', strtolower(trim($validated['email'])))->first();

        if ($user === null) {
            return response()->json(['message' => 'That account no longer exists.'], 404);
        }

        return response()->json([
            'message' => 'Your email is verified. Welcome to Fati Market.',
            'data' => $this->sessionFor($user),
        ], 200);
    }

    /**
     * Send another code.
     * POST /api/auth/resend-code
     *
     * Says the same thing whether or not the address has an account, so it
     * cannot be used to find out who is registered.
     */
    public function resendCode(Request $request)
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', strtolower(trim($validated['email'])))->first();

        if ($user !== null && $user->email_verified_at === null) {
            $this->verification->send($user->email, $user->studentInfo?->first_name ?? 'there');
        }

        return response()->json([
            'message' => 'If that address is waiting to be verified, a new code is on its way.',
        ], 200);
    }

    /** Upload one image and return its URL. */
    private function uploadImage($file, string $folder): string
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
            throw new \RuntimeException('Failed to upload the photo.');
        }

        return $result['secure_url'];
    }

    /** The payload the app stores after any successful sign-in. */
    private function sessionFor(User $user): array
    {
        $user->update(['is_active' => true]);
        $info = StudentInformation::where('user_id', $user->user_id)->first();

        $lifetimeMinutes = (int) config('sanctum.expiration');
        $expiresAt = $lifetimeMinutes > 0 ? now()->addMinutes($lifetimeMinutes) : null;

        return [
            'token' => $user->createToken('auth_token', ['*'], $expiresAt)->plainTextToken,
            'expires_in' => $lifetimeMinutes > 0 ? $lifetimeMinutes * 60 : 0,
            'user_id' => $user->user_id,
            'email' => $user->email,
            'role' => $user->role,
            'first_name' => $info?->first_name,
            'last_name' => $info?->last_name,
            'profile_picture' => $info?->profile_picture,
            'wallet_points' => $user->wallet_points,
        ];
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'ends_with:@student.fatima.edu.ph'],
            'password' => ['required', 'string'],
        ]);

        try {
            // Find user by email
            $user = User::where('email', $validated['email'])->first();

            // Check if user exists
            if (!$user) {
                return response()->json([
                    'message' => 'Invalid credentials',
                ], 401);
            }

            // Check if password is correct
            if (!Hash::check($validated['password'], $user->password)) {
                return response()->json([
                    'message' => 'Invalid credentials',
                ], 401);
            }

            // A student must have proven their address. Accounts approved the
            // old way - an admin reading an uploaded document - still count, so
            // bringing in this check locks nobody out of an account they had.
            if ($user->role === User::ROLE_STUDENT && !$this->verification->isVerified($user)) {
                return response()->json([
                    'message' => 'Verify your email first - we can send you a new code.',
                    'needs_email_verification' => true,
                    'email' => $user->email,
                ], 403);
            }

            // Mark user as active
            $user->update(['is_active' => true]);

            // Get student information
            $studentInfo = StudentInformation::where('user_id', $user->user_id)->first();

            // Generate Sanctum token. Its life is config('sanctum.expiration')
            // minutes, and the same window is stamped on the row so the token
            // carries its own deadline for pruning and for the client.
            $lifetimeMinutes = (int) config('sanctum.expiration');
            $expiresAt = $lifetimeMinutes > 0 ? now()->addMinutes($lifetimeMinutes) : null;
            $token = $user->createToken('auth_token', ['*'], $expiresAt)->plainTextToken;

            return response()->json([
                'message' => 'Login successful',
                'data' => [
                    'user_id' => $user->user_id,
                    'email' => $user->email,
                    'first_name' => $studentInfo?->first_name,
                    'last_name' => $studentInfo?->last_name,
                    'profile_picture' => $studentInfo?->profile_picture,
                    'role' => $user->role,
                    'wallet_points' => $user->wallet_points,
                    'token' => $token,
                    'expires_at' => $expiresAt?->toIso8601String(),
                    // Seconds, so a client can age the session off its own
                    // clock instead of trusting the two to be in sync.
                    'expires_in' => $expiresAt ? $lifetimeMinutes * 60 : null,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Login failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Logout user and invalidate token
     * POST /api/logout
     */
    public function logout(Request $request)
    {
        try {
            // Get the currently authenticated user
            $user = $request->user();

            // Mark user as inactive
            $user->update(['is_active' => false]);

            // Revoke the token that was used to authenticate the current request
            $user->currentAccessToken()->delete();

            return response()->json([
                'message' => 'Logged out successfully',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Logout failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update profile picture
     * PUT /api/profile/picture
     */
    public function updateProfilePicture(Request $request)
    {
        try {
            
            // Only students need verification check
            if ($request->user()->role === 'student') {
                $verification = StudentVerification::where('user_id', $request->user()->user_id)->first();
                if (!$verification || !$verification->is_verified) {
                    return response()->json([
                        'message' => 'Your account is not verified yet. Please wait for admin approval.',
                    ], 403);
                }
            }

            $request->validate([
                'profile_picture' => ['required', 'image', 'max:5120', 'mimes:jpg,jpeg,png'],
            ]);

            // Initialize Cloudinary
            $cloudinary = new Cloudinary([
                'cloud' => [
                    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                    'api_key' => env('CLOUDINARY_KEY'),
                    'api_secret' => env('CLOUDINARY_SECRET'),
                ]
            ]);

            // Upload profile picture to Cloudinary
            $profileUploadResult = $cloudinary->uploadApi()->upload(
                $request->file('profile_picture')->getRealPath(),
                [
                    'folder' => 'student_profiles',
                    'resource_type' => 'image',
                ]
            );

            if (!isset($profileUploadResult['secure_url'])) {
                return response()->json([
                    'message' => 'Failed to upload profile picture',
                    'error' => 'Cloudinary upload error',
                ], 500);
            }

            $profilePictureUrl = $profileUploadResult['secure_url'];

            // Get current user's student information
            $studentInfo = StudentInformation::where('user_id', $request->user()->user_id)->first();

            if (!$studentInfo) {
                return response()->json([
                    'message' => 'Student information not found',
                ], 404);
            }

            // Update profile picture
            $studentInfo->update([
                'profile_picture' => $profilePictureUrl,
            ]);

            
            return response()->json([
                'message' => 'Profile picture updated successfully',
                'data' => [
                    'user_id' => $request->user()->user_id,
                    'profile_picture' => $profilePictureUrl,
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Failed to update profile picture',
                'error' => 'Validation failed',
                'validation_errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update profile picture',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get wallet balance for authenticated user
     * GET /api/wallet
     */
    public function getWalletBalance(Request $request)
    {
        try {
            $user = $request->user();

            return response()->json([
                'message' => 'Wallet balance retrieved successfully',
                'data' => [
                    'user_id' => $user->user_id,
                    'email' => $user->email,
                    'role' => $user->role,
                    'wallet_points' => $user->wallet_points,
                    'updated_at' => $user->updated_at,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve wallet balance',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin Dashboard Statistics
     * GET /api/admin/dashboard
     */
    public function getDashboardStats(Request $request)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Admin access required',
                ], 403);
            }

            // Get statistics
            $stats = [
                'users' => [
                    'total_students' => User::where('role', 'student')->count(),
                    'active_students' => User::where('role', 'student')->where('is_active', true)->count(),
                    'pending_students' => StudentVerification::where('status', 'pending')->count(),
                    'verified_students' => StudentVerification::where('is_verified', true)->count(),
                ],
                'items' => [
                    'total_items' => \App\Models\Item::count(),
                    'private_items' => \App\Models\Item::where('status', 'private')->count(),
                    'public_items' => \App\Models\Item::where('status', 'public')->count(),
                    'acquired_items' => \App\Models\Item::where('status', 'acquired')->count(),
                    'reserved_items' => \App\Models\Item::where('status', 'reserved')->count(),
                    'sold_items' => \App\Models\Item::where('status', 'sold')->count(),
                ],
                'recent_activities' => [
                    'recent_registrations' => User::where('role', 'student')
                        ->orderBy('created_at', 'desc')
                        ->limit(5)
                        ->with('studentInfo')
                        ->get(['user_id', 'email', 'created_at'])
                        ->map(function ($user) {
                            return [
                                'user_id' => $user->user_id,
                                'email' => $user->email,
                                'name' => $user->studentInfo?->first_name . ' ' . $user->studentInfo?->last_name,
                                'created_at' => $user->created_at,
                            ];
                        }),
                    'recent_items' => \App\Models\Item::with(['seller', 'photos'])
                        ->orderBy('created_at', 'desc')
                        ->limit(5)
                        ->get()
                        ->map(function ($item) {
                            return [
                                'item_id' => $item->item_id,
                                'title' => $item->title,
                                'seller' => $item->seller->email,
                                'status' => $item->status,
                                'price_points' => $item->price_points,
                                'created_at' => $item->created_at,
                                'photos' => $item->photos->pluck('photo_url')->toArray(),
                            ];
                        }),
                    'pending_verifications' => StudentVerification::with(['user.studentInfo'])
                        ->where('status', 'pending')
                        ->orderBy('created_at', 'desc')
                        ->limit(5)
                        ->get()
                        ->map(function ($verification) {
                            return [
                                'verification_id' => $verification->student_verification_id,
                                'user_id' => $verification->user_id,
                                'student_name' => $verification->user->studentInfo?->first_name . ' ' . $verification->user->studentInfo?->last_name,
                                'email' => $verification->user->email,
                                'verification_use' => $verification->verification_use,
                                'created_at' => $verification->created_at,
                            ];
                        }),
                ],
            ];

            return response()->json([
                'message' => 'Dashboard statistics retrieved successfully',
                'data' => $stats,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve dashboard statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
