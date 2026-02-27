<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StudentManagementController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    // Admin student management routes
    Route::prefix('admin/students')->group(function () {
        Route::get('/', [StudentManagementController::class, 'getAllStudents']);
        Route::get('/pending', [StudentManagementController::class, 'getPendingStudents']);
        Route::put('/{user_id}/approve', [StudentManagementController::class, 'approveStudent']);
        Route::put('/{user_id}/decline', [StudentManagementController::class, 'declineStudent']);
        Route::get('/{user_id}', [StudentManagementController::class, 'getStudentDetails']);
    });
});