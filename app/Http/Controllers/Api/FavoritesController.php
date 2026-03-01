<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FavoritesController extends Controller
{
    /**
     * Add an item to favorites
     * POST /api/favorites
     */
    public function addFavorite(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'item_id' => ['required', 'integer', 'exists:items,item_id'],
            ]);

            $userId = $request->user()->user_id;
            $itemId = $validated['item_id'];

            // Check if item already favorited
            $existingFavorite = Favorite::where('user_id', $userId)
                ->where('item_id', $itemId)
                ->first();

            if ($existingFavorite) {
                return response()->json([
                    'message' => 'Item already favorited',
                ], 409);
            }

            // Add to favorites
            $favorite = Favorite::create([
                'user_id' => $userId,
                'item_id' => $itemId,
            ]);

            Log::info('Item added to favorites', [
                'user_id' => $userId,
                'item_id' => $itemId,
            ]);

            return response()->json([
                'message' => 'Item added to favorites',
                'data' => [
                    'favorite_id' => $favorite->favorite_id,
                    'user_id' => $favorite->user_id,
                    'item_id' => $favorite->item_id,
                    'created_at' => $favorite->created_at,
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error adding favorite', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to add favorite',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove an item from favorites
     * DELETE /api/favorites/{item_id}
     */
    public function removeFavorite(Request $request, $itemId)
    {
        try {
            $userId = $request->user()->user_id;

            // Check if item exists
            $item = Item::find($itemId);
            if (!$item) {
                return response()->json([
                    'message' => 'Item not found',
                ], 404);
            }

            // Find and delete favorite
            $favorite = Favorite::where('user_id', $userId)
                ->where('item_id', $itemId)
                ->first();

            if (!$favorite) {
                return response()->json([
                    'message' => 'Item not in favorites',
                ], 404);
            }

            $favorite->delete();

            Log::info('Item removed from favorites', [
                'user_id' => $userId,
                'item_id' => $itemId,
            ]);

            return response()->json([
                'message' => 'Item removed from favorites',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error removing favorite', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to remove favorite',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all favorites for current user
     * GET /api/favorites
     */
    public function getFavorites(Request $request)
    {
        try {
            $userId = $request->user()->user_id;

            // Get all favorites with item details
            $favorites = Favorite::where('user_id', $userId)
                ->with([
                    'item' => function ($q) {
                        $q->select('item_id', 'seller_id', 'title', 'description', 'category_id', 'price_points', 'markup_points', 'status', 'created_at');
                    },
                    'item.seller' => function ($q) {
                        $q->select('user_id', 'email');
                    },
                    'item.photos' => function ($q) {
                        $q->select('photo_id', 'item_id', 'photo_url');
                    }
                ])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($favorite) {
                    return [
                        'favorite_id' => $favorite->favorite_id,
                        'item_id' => $favorite->item_id,
                        'item' => [
                            'item_id' => $favorite->item->item_id,
                            'seller_id' => $favorite->item->seller_id,
                            'seller_email' => $favorite->item->seller->email,
                            'title' => $favorite->item->title,
                            'description' => $favorite->item->description,
                            'category_id' => $favorite->item->category_id,
                            'price_points' => $favorite->item->price_points,
                            'markup_points' => $favorite->item->markup_points,
                            'status' => $favorite->item->status,
                            'photos' => $favorite->item->photos->pluck('photo_url')->toArray(),
                            'created_at' => $favorite->item->created_at,
                        ],
                        'favorited_at' => $favorite->created_at,
                    ];
                });

            return response()->json([
                'message' => 'Favorites retrieved successfully',
                'data' => $favorites,
                'count' => $favorites->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting favorites', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve favorites',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if item is favorited by current user
     * GET /api/favorites/{item_id}/check
     */
    public function checkFavorite(Request $request, $itemId)
    {
        try {
            $userId = $request->user()->user_id;

            // Check if item exists
            $item = Item::find($itemId);
            if (!$item) {
                return response()->json([
                    'message' => 'Item not found',
                ], 404);
            }

            // Check if favorited
            $isFavorited = Favorite::where('user_id', $userId)
                ->where('item_id', $itemId)
                ->exists();

            return response()->json([
                'message' => 'Check complete',
                'data' => [
                    'item_id' => $itemId,
                    'is_favorited' => $isFavorited,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error checking favorite', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to check favorite',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
