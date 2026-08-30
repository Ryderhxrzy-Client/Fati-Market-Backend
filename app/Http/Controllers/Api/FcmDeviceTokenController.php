<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FcmDeviceToken;
use Illuminate\Http\Request;

class FcmDeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:4096'], 'device_id' => ['nullable', 'string', 'max:255'], 'platform' => ['nullable', 'string', 'max:20']]);
        $tokenHash = hash('sha256', $data['token']);
        FcmDeviceToken::updateOrCreate(
            ['token_hash' => $tokenHash],
            array_merge($data, ['token_hash' => $tokenHash, 'user_id' => $request->user()->user_id, 'last_seen_at' => now()])
        );
        return response()->json(['message' => 'FCM token registered']);
    }

    public function destroy(Request $request)
    {
        $request->validate(['token' => ['required', 'string', 'max:4096']]);
        FcmDeviceToken::where('user_id', $request->user()->user_id)->where('token_hash', hash('sha256', $request->token))->delete();
        return response()->json(['message' => 'FCM token removed']);
    }
}
