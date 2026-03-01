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
    try {
        // Explicitly ensure Sanctum user is available
        $user = auth('sanctum')->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Perform the broadcast authentication
        $auth = Broadcast::auth($request);

        // Ensure response is JSON
        if (is_array($auth) || $auth instanceof \ArrayAccess) {
            return response()->json($auth);
        }

        return $auth;
    } catch (\Exception $e) {
        return response()->json(['message' => 'Authorization failed', 'error' => $e->getMessage()], 403);
    }
});
