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
    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users', 'ends_with:@student.fatima.edu.ph'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'student_id_photo' => ['required', 'image', 'max:5120', 'mimes:jpg,jpeg,png'],
            'profile_picture' => ['required', 'image', 'max:5120', 'mimes:jpg,jpeg,png'],
            'verification_use' => ['required', 'in:registration_card,student_id'],
        ]);

        try {
            // Upload photo to Cloudinary
            $cloudinary = new Cloudinary([
                'cloud' => [
                    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                    'api_key' => env('CLOUDINARY_KEY'),
                    'api_secret' => env('CLOUDINARY_SECRET'),
                ]
            ]);

            $uploadResult = $cloudinary->uploadApi()->upload(
                $request->file('student_id_photo')->getRealPath(),
                [
                    'folder' => 'student_ids',
                    'resource_type' => 'image',
                ]
            );

            $photoUrl = $uploadResult['secure_url'];

            // Upload profile picture to Cloudinary
            $profileUploadResult = $cloudinary->uploadApi()->upload(
                $request->file('profile_picture')->getRealPath(),
                [
                    'folder' => 'student_profiles',
                    'resource_type' => 'image',
                ]
            );

            $profilePictureUrl = $profileUploadResult['secure_url'];

            // Create user and related records in transaction
            $result = DB::transaction(function () use ($validated, $photoUrl, $profilePictureUrl) {
                // Create user
                $user = User::create([
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'wallet_points' => 0,
                    'role' => 'student',
                    'is_active' => false,
                ]);

                // Create student information
                $studentInfo = StudentInformation::create([
                    'user_id' => $user->user_id,
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'profile_picture' => $profilePictureUrl,
                ]);

                // Create student verification
                StudentVerification::create([
                    'user_id' => $user->user_id,
                    'verification_use' => $validated['verification_use'],
                    'link' => $photoUrl,
                    'is_verified' => false,
                ]);

                return [
                    'user_id' => $user->user_id,
                    'student_id' => $studentInfo->student_id,
                ];
            });

            return response()->json([
                'message' => 'Registration successful. Please wait for admin approval.',
                'data' => [
                    'user_id' => $result['user_id'],
                    'student_id' => $result['student_id'],
                    'email' => $validated['email'],
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
            ], 500);
        }
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

            // Check if user account is active (approved by admin)
            if (!$user->is_active) {
                return response()->json([
                    'message' => 'Account is not yet approved by admin',
                ], 403);
            }

            // Get student information
            $studentInfo = StudentInformation::where('user_id', $user->user_id)->first();

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
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Login failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
