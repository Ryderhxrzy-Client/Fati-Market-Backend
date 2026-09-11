<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\StoreHours;

/**
 * Facts about the store itself, served from config so Ofelia can change them
 * without an app release.
 */
class StoreController extends Controller
{
    /**
     * When the store is open.
     * GET /api/store/hours
     *
     * The meet-up picker is drawn from this, and the same figures validate
     * the booking, so the app can never offer a slot the server refuses.
     */
    public function hours()
    {
        return response()->json([
            'message' => 'Store hours retrieved successfully',
            'data' => StoreHours::toArray(),
        ], 200);
    }
}
