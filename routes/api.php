<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StudentManagementController;
use App\Http\Controllers\Api\MessagesController;
use App\Http\Controllers\Api\ItemsController;
use App\Http\Controllers\Api\CategoriesController;
use App\Http\Controllers\Api\FavoritesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public categories routes (can view categories without auth)
Route::get('/categories', [CategoriesController::class, 'getAllCategories']);
Route::get('/categories/{category_id}', [CategoriesController::class, 'getCategoryById']);

// Public items routes (can view items without auth, but supports optional Sanctum auth)
Route::get('/items', [ItemsController::class, 'getAllItems']);
Route::get('/items/{item_id}', [ItemsController::class, 'getItemDetails']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    // Profile routes
    Route::post('/profile/picture', [AuthController::class, 'updateProfilePicture']);

    // Admin student management routes
    Route::prefix('admin/students')->group(function () {
        Route::get('/', [StudentManagementController::class, 'getAllStudents']);
        Route::get('/pending', [StudentManagementController::class, 'getPendingStudents']);
        Route::put('/{user_id}/approve', [StudentManagementController::class, 'approveStudent']);
        Route::put('/{user_id}/decline', [StudentManagementController::class, 'declineStudent']);
        Route::put('/{user_id}/block', [StudentManagementController::class, 'blockStudent']);
        Route::get('/{user_id}', [StudentManagementController::class, 'getStudentDetails']);
    });

    // Messages routes
    Route::prefix('messages')->group(function () {
        Route::post('/{item_id}', [MessagesController::class, 'sendMessage']);
        Route::get('/{item_id}', [MessagesController::class, 'getMessagesByItem']);
        Route::delete('/{message_id}', [MessagesController::class, 'deleteMessage']);
    });

    // Conversations routes
    Route::prefix('conversations')->group(function () {
        Route::get('/', [MessagesController::class, 'getConversations']);
        Route::get('/{user_id}', [MessagesController::class, 'getConversationWithUser']);
    });

    // Protected items routes
    Route::prefix('items')->group(function () {
        Route::post('/', [ItemsController::class, 'createItem']);
        Route::put('/{item_id}', [ItemsController::class, 'updateItem']);
        Route::delete('/{item_id}', [ItemsController::class, 'deleteItem']);
    });

    // Favorites routes
    Route::prefix('favorites')->group(function () {
        Route::post('/', [FavoritesController::class, 'addFavorite']);
        Route::get('/', [FavoritesController::class, 'getFavorites']);
        Route::delete('/{item_id}', [FavoritesController::class, 'removeFavorite']);
        Route::get('/{item_id}/check', [FavoritesController::class, 'checkFavorite']);
    });
});