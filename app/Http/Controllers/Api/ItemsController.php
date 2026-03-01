<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemPhoto;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ItemsController extends Controller
{
    /**
     * Create a new item
     * POST /api/items
     */
    public function createItem(Request $request)
    {
        try {
            // Validate request - photos can be single file or array
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'description' => ['required', 'string', 'max:1000'],
                'category_id' => ['required', 'integer', 'exists:categories,category_id'],
                'price_points' => ['required', 'integer', 'min:0'],
                'photos' => ['required'],
            ]);

            // Get photos and ensure they're in an array (single file or multiple)
            $photos = $request->file('photos');
            if (!is_array($photos)) {
                $photos = [$photos];
            }

            // Validate each photo
            foreach ($photos as $photo) {
                if (!$photo || !$photo->isValid()) {
                    return response()->json([
                        'message' => 'Invalid file uploaded',
                        'error' => 'One or more files are invalid',
                    ], 422);
                }
                if (!in_array($photo->getMimeType(), ['image/jpeg', 'image/png'])) {
                    return response()->json([
                        'message' => 'Invalid file type',
                        'error' => 'Only JPG and PNG images are allowed',
                    ], 422);
                }
                if ($photo->getSize() > 5120 * 1024) {
                    return response()->json([
                        'message' => 'File too large',
                        'error' => 'Maximum file size is 5MB',
                    ], 422);
                }
            }

            // Initialize Cloudinary
            $cloudinary = new Cloudinary([
                'cloud' => [
                    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                    'api_key' => env('CLOUDINARY_KEY'),
                    'api_secret' => env('CLOUDINARY_SECRET'),
                ]
            ]);

            Log::info('Creating new item', [
                'seller_id' => $request->user()->user_id,
                'title' => $validated['title'],
            ]);

            // Create item
            $item = Item::create([
                'seller_id' => $request->user()->user_id,
                'title' => $validated['title'],
                'description' => $validated['description'],
                'category_id' => $validated['category_id'],
                'price_points' => $validated['price_points'],
                'markup_points' => 0,
                'status' => 'private',
            ]);

            Log::info('Item created', ['item_id' => $item->item_id]);

            // Upload photos to Cloudinary and create records
            $photoUrls = [];
            foreach ($photos as $photo) {
                try {
                    Log::info('Uploading photo to Cloudinary', [
                        'item_id' => $item->item_id,
                        'file' => $photo->getClientOriginalName(),
                    ]);

                    $uploadResult = $cloudinary->uploadApi()->upload(
                        $photo->getRealPath(),
                        [
                            'folder' => 'items',
                            'resource_type' => 'image',
                        ]
                    );

                    $photoUrl = $uploadResult['secure_url'];

                    // Save photo record
                    ItemPhoto::create([
                        'item_id' => $item->item_id,
                        'photo_url' => $photoUrl,
                    ]);

                    $photoUrls[] = $photoUrl;

                    Log::info('Photo uploaded successfully', ['photo_url' => $photoUrl]);
                } catch (\Exception $e) {
                    Log::error('Failed to upload photo', ['error' => $e->getMessage()]);
                    // Continue with other photos even if one fails
                }
            }

            return response()->json([
                'message' => 'Item created successfully',
                'data' => [
                    'item_id' => $item->item_id,
                    'seller_id' => $item->seller_id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'category_id' => $item->category_id,
                    'price_points' => $item->price_points,
                    'status' => $item->status,
                    'photos' => $photoUrls,
                    'created_at' => $item->created_at,
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating item', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to create item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all items with optional filters
     * GET /api/items?status=public&category_id=1
     *
     * Status filter:
     * - private: Only shows current user's private items (requires authentication)
     * - public: Shows all public items
     * - acquired, reserved, sold: Shows all items with these statuses
     */
    public function getAllItems(Request $request)
    {
        try {
            // Build query
            $query = Item::with([
                'seller' => function ($q) {
                    $q->select('user_id', 'email');
                },
                'photos' => function ($q) {
                    $q->select('photo_id', 'item_id', 'photo_url');
                }
            ]);

            // Status filtering logic
            if ($request->has('status')) {
                $status = $request->query('status');

                // Validate status is allowed
                if (!in_array($status, ['private', 'public', 'acquired', 'reserved', 'sold'])) {
                    return response()->json([
                        'message' => 'Invalid status. Allowed values: private, public, acquired, reserved, sold',
                    ], 422);
                }

                if ($status === 'private') {
                    // For private items, only show current user's items
                    if (!$request->user()) {
                        return response()->json([
                            'message' => 'Authentication required to view private items',
                        ], 401);
                    }
                    $query->where('status', 'private')
                        ->where('seller_id', $request->user()->user_id);
                } else {
                    // For public, acquired, reserved, sold - show all items
                    $query->where('status', $status);
                }
            } else {
                // If no status specified, default to showing public items
                $query->where('status', 'public');
            }

            // Category filtering
            if ($request->has('category_id')) {
                $query->where('category_id', $request->query('category_id'));
            }

            if ($request->has('category')) {
                // Filter by category name for backwards compatibility
                $query->whereHas('category', function ($q) {
                    $q->where('name', 'like', '%' . request()->query('category') . '%');
                });
            }

            // Price filtering (for private items only)
            if ($request->has('price_min')) {
                $query->where('price_points', '>=', $request->query('price_min'));
            }

            if ($request->has('price_max')) {
                $query->where('price_points', '<=', $request->query('price_max'));
            }

            if ($request->has('seller_id')) {
                $query->where('seller_id', $request->query('seller_id'));
            }

            // Get items ordered by newest first
            $items = $query->orderBy('created_at', 'desc')->get()
                ->map(function ($item) {
                    // For private items, show price_points
                    // For public/acquired/reserved/sold items, show markup_points
                    if ($item->status === 'private') {
                        $points = $item->price_points;
                        $points_label = 'price_points';
                    } else {
                        $points = $item->markup_points;
                        $points_label = 'markup_points';
                    }

                    return [
                        'item_id' => $item->item_id,
                        'seller_id' => $item->seller_id,
                        'seller_email' => $item->seller->email,
                        'title' => $item->title,
                        'description' => $item->description,
                        'category_id' => $item->category_id,
                        $points_label => $points,
                        'status' => $item->status,
                        'photos' => $item->photos->pluck('photo_url')->toArray(),
                        'created_at' => $item->created_at,
                    ];
                });

            return response()->json([
                'message' => 'Items retrieved successfully',
                'data' => $items,
                'count' => $items->count(),
                'filters' => [
                    'status' => $request->query('status') ?? 'public',
                    'category_id' => $request->query('category_id'),
                    'seller_id' => $request->query('seller_id'),
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting items', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve items',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get specific item details
     * GET /api/items/{item_id}
     */
    public function getItemDetails(Request $request, $itemId)
    {
        try {
            $item = Item::with([
                'seller' => function ($q) {
                    $q->select('user_id', 'email');
                },
                'photos' => function ($q) {
                    $q->select('photo_id', 'item_id', 'photo_url');
                }
            ])->where('item_id', $itemId)->first();

            if (!$item) {
                return response()->json([
                    'message' => 'Item not found',
                ], 404);
            }

            return response()->json([
                'message' => 'Item details retrieved successfully',
                'data' => [
                    'item_id' => $item->item_id,
                    'seller_id' => $item->seller_id,
                    'seller_email' => $item->seller->email,
                    'title' => $item->title,
                    'description' => $item->description,
                    'category_id' => $item->category_id,
                    'price_points' => $item->price_points,
                    'markup_points' => $item->markup_points,
                    'status' => $item->status,
                    'photos' => $item->photos->pluck('photo_url')->toArray(),
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting item details', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to retrieve item details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an item
     * PUT /api/items/{item_id}
     */
    public function updateItem(Request $request, $itemId)
    {
        try {
            $item = Item::where('item_id', $itemId)->first();

            if (!$item) {
                return response()->json([
                    'message' => 'Item not found',
                ], 404);
            }

            // Check ownership
            if ($item->seller_id !== $request->user()->user_id) {
                return response()->json([
                    'message' => 'Unauthorized. You can only update your own items.',
                ], 403);
            }

            // Validate request
            $validated = $request->validate([
                'title' => ['string', 'max:255'],
                'description' => ['string', 'max:1000'],
                'category_id' => ['integer', 'exists:categories,category_id'],
                'price_points' => ['integer', 'min:0'],
                'status' => ['in:private,acquired,public,reserved,sold'],
            ]);

            Log::info('Updating item', ['item_id' => $itemId]);

            // Update only provided fields
            $item->update($validated);

            return response()->json([
                'message' => 'Item updated successfully',
                'data' => [
                    'item_id' => $item->item_id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'category_id' => $item->category_id,
                    'price_points' => $item->price_points,
                    'status' => $item->status,
                    'updated_at' => $item->updated_at,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error updating item', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to update item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an item
     * DELETE /api/items/{item_id}
     */
    public function deleteItem(Request $request, $itemId)
    {
        try {
            $item = Item::where('item_id', $itemId)->first();

            if (!$item) {
                return response()->json([
                    'message' => 'Item not found',
                ], 404);
            }

            // Check ownership
            if ($item->seller_id !== $request->user()->user_id) {
                return response()->json([
                    'message' => 'Unauthorized. You can only delete your own items.',
                ], 403);
            }

            Log::info('Deleting item', ['item_id' => $itemId]);

            // Delete photos first
            ItemPhoto::where('item_id', $itemId)->delete();

            // Delete item
            $item->delete();

            return response()->json([
                'message' => 'Item deleted successfully',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error deleting item', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to delete item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
