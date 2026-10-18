<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentInformation;
use App\Models\StudentVerification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentManagementController extends Controller
{
    /**
     * Get all pending students for verification
     * GET /api/admin/students/pending
     */
    public function getPendingStudents(Request $request)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Unauthorized. Only admins can view student management.',
                ], 403);
            }

            // Get all unverified students
            $pendingStudents = StudentVerification::with([
                'user' => function ($query) {
                    $query->select('user_id', 'email', 'wallet_points', 'is_active', 'created_at');
                },
                'user.studentInfo' => function ($query) {
                    $query->select('user_id', 'first_name', 'last_name', 'profile_picture');
                }
            ])
                ->where('is_verified', false)
                ->get()
                ->map(function ($verification) {
                    return [
                        'student_verification_id' => $verification->student_verification_id,
                        'user_id' => $verification->user_id,
                        'email' => $verification->user->email,
                        'first_name' => $verification->user->studentInfo?->first_name,
                        'last_name' => $verification->user->studentInfo?->last_name,
                        'profile_picture' => $verification->user->studentInfo?->profile_picture,
                        'verification_document' => $verification->link,
                        'verification_type' => $verification->verification_use,
                        'is_verified' => $verification->is_verified,
                        'registered_date' => $verification->user->created_at,
                        'status' => $verification->is_verified ? 'approved' : 'pending',
                    ];
                });

            return response()->json([
                'message' => 'Pending students retrieved successfully',
                'data' => $pendingStudents,
                'count' => $pendingStudents->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting pending students', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve students',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all users (all registered users) with optional status filter
     * GET /api/admin/students?status=pending|approved|declined|blocked|active
     */
    public function getAllStudents(Request $request)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Unauthorized. Only admins can view user management.',
                ], 403);
            }

            // Get status filter from query parameter
            $statusFilter = $request->query('status');

            // Build query: fetch all non-admin users
            $users = User::with([
                'studentInfo',
                'authIdentities',
                'verification',
            ])
                ->where('role', '!=', User::ROLE_ADMIN)
                ->latest('created_at')
                ->get();

            // Map and attach registration_method and status
            $allStudents = $users->map(function ($user) {
                $verification = $user->verification;
                $isGoogle = $user->authIdentities->where('provider', 'google')->isNotEmpty();
                $registrationMethod = $isGoogle ? 'Google' : 'Email & Password';
                $isBlocked = !$user->is_active || ($verification?->status === 'blocked');
                $status = $isBlocked ? 'blocked' : ($verification?->status ?? 'active');

                return [
                    'student_verification_id' => $verification?->student_verification_id ?? $user->user_id,
                    'user_id' => $user->user_id,
                    'email' => $user->email,
                    'first_name' => $user->studentInfo?->first_name ?? 'User',
                    'last_name' => $user->studentInfo?->last_name ?? ('#' . $user->user_id),
                    'profile_picture' => $user->studentInfo?->profile_picture,
                    'verification_document' => $verification?->link,
                    'verification_type' => $verification?->verification_use ?? 'none',
                    'is_verified' => (bool) ($user->email_verified_at !== null || $verification?->is_verified || $isGoogle),
                    'wallet_points' => (int) ($user->wallet_points ?? 0),
                    'is_active' => (bool) $user->is_active,
                    'registered_date' => $user->created_at,
                    'registration_method' => $registrationMethod,
                    'status' => $status,
                    'reason' => $verification?->reason,
                ];
            });

            // Apply status filter if provided
            if ($statusFilter) {
                if ($statusFilter === 'blocked') {
                    $allStudents = $allStudents->where('status', 'blocked')->values();
                } elseif ($statusFilter === 'active') {
                    $allStudents = $allStudents->where('status', '!=', 'blocked')->values();
                } else {
                    $allStudents = $allStudents->where('status', $statusFilter)->values();
                }
            }

            return response()->json([
                'message' => 'Users retrieved successfully',
                'data' => $allStudents,
                'count' => $allStudents->count(),
                'filter' => $statusFilter ?? 'all',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting all users', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve users',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get specific student details
     * GET /api/admin/students/{user_id}
     */
    public function getStudentDetails(Request $request, $userId)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Unauthorized. Only admins can view student management.',
                ], 403);
            }

            $verification = StudentVerification::with([
                'user' => function ($query) {
                    $query->select('user_id', 'email', 'wallet_points', 'is_active', 'created_at');
                },
                'user.studentInfo' => function ($query) {
                    $query->select('user_id', 'first_name', 'last_name', 'profile_picture');
                }
            ])->where('user_id', $userId)->first();

            if (!$verification) {
                return response()->json([
                    'message' => 'Student not found',
                ], 404);
            }

            $studentData = [
                'student_verification_id' => $verification->student_verification_id,
                'user_id' => $verification->user_id,
                'email' => $verification->user->email,
                'first_name' => $verification->user->studentInfo?->first_name,
                'last_name' => $verification->user->studentInfo?->last_name,
                'profile_picture' => $verification->user->studentInfo?->profile_picture,
                'verification_document' => $verification->link,
                'verification_type' => $verification->verification_use,
                'is_verified' => $verification->is_verified,
                'reason' => $verification->reason,
                'wallet_points' => $verification->user->wallet_points,
                'is_active' => $verification->user->is_active,
                'registered_date' => $verification->user->created_at,
                'status' => $verification->is_verified ? 'approved' : 'pending',
            ];

            return response()->json([
                'message' => 'Student details retrieved successfully',
                'data' => $studentData,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting student details', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve student details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve a student
     * PUT /api/admin/students/{user_id}/approve
     */
    public function approveStudent(Request $request, $userId)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Unauthorized. Only admins can approve students.',
                ], 403);
            }

            $verification = StudentVerification::where('user_id', $userId)->first();

            if (!$verification) {
                return response()->json([
                    'message' => 'Student not found',
                ], 404);
            }

            // Mark as verified
            $verification->update([
                'is_verified' => true,
                'status' => 'approved',
                'reason' => null,
            ]);

            // Log the action
            Log::info('Student approved', [
                'admin_id' => $request->user()->user_id,
                'student_user_id' => $userId,
            ]);

            return response()->json([
                'message' => 'Student approved successfully',
                'data' => [
                    'user_id' => $userId,
                    'is_verified' => true,
                    'status' => 'approved',
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error approving student', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to approve student',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Decline a student
     * PUT /api/admin/students/{user_id}/decline
     */
    public function declineStudent(Request $request, $userId)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Unauthorized. Only admins can decline students.',
                ], 403);
            }

            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:255'],
            ]);

            $verification = StudentVerification::where('user_id', $userId)->first();

            if (!$verification) {
                return response()->json([
                    'message' => 'Student not found',
                ], 404);
            }

            // Mark as declined (keep is_verified = false but add reason and status)
            $verification->update([
                'is_verified' => false,
                'status' => 'declined',
                'reason' => $validated['reason'],
            ]);

            // Log the action
            Log::info('Student declined', [
                'admin_id' => $request->user()->user_id,
                'student_user_id' => $userId,
                'reason' => $validated['reason'],
            ]);

            return response()->json([
                'message' => 'Student declined successfully',
                'data' => [
                    'user_id' => $userId,
                    'is_verified' => false,
                    'reason' => $validated['reason'],
                    'status' => 'declined',
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error declining student', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to decline student',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Block a user
     * PUT /api/admin/students/{user_id}/block
     */
    public function blockStudent(Request $request, $userId)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Unauthorized. Only admins can block users.',
                ], 403);
            }

            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:255'],
            ]);

            $user = User::find($userId);
            if (!$user) {
                $verification = StudentVerification::find($userId);
                if ($verification) {
                    $user = User::find($verification->user_id);
                }
            }

            if (!$user) {
                return response()->json([
                    'message' => 'User not found',
                ], 404);
            }

            // Deactivate user account
            $user->update([
                'is_active' => false,
            ]);

            // Update verification status if present
            $verification = StudentVerification::where('user_id', $user->user_id)->first();
            if ($verification) {
                $verification->update([
                    'status' => 'blocked',
                    'reason' => $validated['reason'],
                ]);
            }

            // Immediately revoke active auth tokens so the user is logged out
            $user->tokens()->delete();

            // Log the action
            Log::info('User blocked', [
                'admin_id' => $request->user()->user_id,
                'user_id' => $user->user_id,
                'reason' => $validated['reason'],
            ]);

            return response()->json([
                'message' => 'User blocked successfully',
                'data' => [
                    'user_id' => $user->user_id,
                    'status' => 'blocked',
                    'reason' => $validated['reason'],
                    'is_active' => false,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error blocking user', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to block user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Unblock a user
     * PUT /api/admin/students/{user_id}/unblock
     */
    public function unblockStudent(Request $request, $userId)
    {
        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'message' => 'Unauthorized. Only admins can unblock users.',
                ], 403);
            }

            $user = User::find($userId);
            if (!$user) {
                $verification = StudentVerification::find($userId);
                if ($verification) {
                    $user = User::find($verification->user_id);
                }
            }

            if (!$user) {
                return response()->json([
                    'message' => 'User not found',
                ], 404);
            }

            // Reactivate user account
            $user->update([
                'is_active' => true,
            ]);

            // Update verification status if present
            $verification = StudentVerification::where('user_id', $user->user_id)->first();
            if ($verification) {
                $verification->update([
                    'status' => 'approved',
                    'reason' => null,
                ]);
            }

            // Log the action
            Log::info('User unblocked', [
                'admin_id' => $request->user()->user_id,
                'user_id' => $user->user_id,
            ]);

            return response()->json([
                'message' => 'User unblocked successfully',
                'data' => [
                    'user_id' => $user->user_id,
                    'status' => 'active',
                    'is_active' => true,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error unblocking user', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to unblock user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
