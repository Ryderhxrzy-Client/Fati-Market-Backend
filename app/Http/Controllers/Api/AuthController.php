<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentInformation;
use App\Models\StudentVerification;
use App\Models\User;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users', Rule::ends_with('@olfu.edu.ph')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'student_id_photo' => ['required', 'image', 'max:5120', 'mimes:jpg,jpeg,png'],
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

            // Create user and related records in transaction
            $result = DB::transaction(function () use ($validated, $photoUrl) {
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
                ]);

                // Create student verification
                StudentVerification::create([
                    'user_id' => $user->user_id,
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
}
