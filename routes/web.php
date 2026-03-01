<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Http\Request;

Route::get('/', function () {
    return view('api-docs');
});

Route::get('/welcome', function () {
    return view('welcome');
});

// Broadcasting authorization endpoint (at root level for Pusher)
Route::post('/broadcasting/auth', function (Request $request) {
    // Explicitly ensure Sanctum user is available
    $user = auth('sanctum')->user();

    if (!$user) {
        return response()->json(['message' => 'Unauthenticated'], 401);
    }

    // Perform the broadcast authentication
    return Broadcast::auth($request);
})->middleware('auth:sanctum');
