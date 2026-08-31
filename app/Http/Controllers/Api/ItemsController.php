<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemPresenter;
use App\Models\Item;
use App\Models\ItemPhoto;
use App\Services\OrderChatNotifier;
use App\Services\PhotoUploader;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ItemsController extends Controller
{
    public function __construct(private readonly PhotoUploader $photos)
    {
    }

    /**
     * Create a new item.
     * POST /api/items
     *
     * The price is a peso amount - the student's asking price - and the
     * listing always lands in `pending`. A student cannot publish their own
     * listing; only Admin can, and only after physical turnover.
     */
    public function createItem(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'description' => ['required', 'string', 'max:1000'],
                'category_id' => ['required', 'integer', 'exists:categories,category_id'],
                // Older app builds still send `price_points`. It is accepted as
                // a peso figure so the deployed app keeps working during the
                // rollout, then normalised below.
                'seller_asking_price' => ['required_without:price_points', 'nullable', 'string', 'max:20'],
                'price_points' => ['required_without:seller_asking_price', 'nullable', 'numeric', 'min:0'],
                'photos' => ['required'],
            ]);

            $askingPrice = $this->parsePrice(
                $validated['seller_asking_price'] ?? $validated['price_points'] ?? null
            );

            if ($askingPrice === null || $askingPrice->isNegative()) {
                return response()->json([
                    'message' => 'Invalid asking price',
                    'errors' => ['seller_asking_price' => ['Enter a valid peso amount, for example 200 or 199.50.']],
                ], 422);
            }

            $photos = $request->file('photos');
            if (!is_array($photos)) {
                $photos = [$photos];
            }

            if ($error = $this->photos->validateImages($photos)) {
                return response()->json($error, 422);
            }

            Log::info('Creating new item', [
                'seller_id' => $request->user()->user_id,
                'title' => $validated['title'],
            ]);

            $item = Item::create([
                'seller_id' => $request->user()->user_id,
                'title' => $validated['title'],
                'description' => $validated['description'],
                'category_id' => $validated['category_id'],
                'seller_asking_price' => $askingPrice->toDecimalString(),
                'price_source' => 'cash',
                'seller_payout_status' => Item::PAYOUT_UNPAID,
                'reward_points' => 0,
                // Newly uploaded items always await Admin review.
                'status' => Item::STATUS_PENDING,
                // Legacy column kept populated so older admin builds and the
                // historical reports still read a sane number.
                'price_points' => intdiv($askingPrice->centavos(), 100),
                'markup_points' => 0,
            ]);

            $photoUrls = $this->photos->uploadMany($photos, 'items', $item->item_id);

            $item->load(['photos', 'seller']);

            // Open the item's conversation with the offer and push it to
            // Admin. The notifier logs its own failures - a chat hiccup must
            // never fail the listing that already exists.
            app(OrderChatNotifier::class)->itemListed($item, $request->user());

            return response()->json([
                'message' => 'Item created successfully',
                'data' => array_merge(ItemPresenter::forSeller($item), ['photos' => $photoUrls]),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error creating item', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to create item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get items with optional filters.
     * GET /api/items?status=public&category_id=1&sort=price_asc
     *
     * Defaults to the public catalog. Only `public` items are ever shown to a
     * buyer; `pending` and `rejected` are scoped to the requesting seller's
     * own listings.
     */
    public function getAllItems(Request $request)
    {
        try {
            $user = $this->resolveOptionalUser($request);

            $query = Item::with([
                'seller' => fn ($q) => $q->select('user_id', 'email'),
                'photos' => fn ($q) => $q->select('photo_id', 'item_id', 'photo_url'),
            ]);

            $status = $request->query('status', Item::STATUS_PUBLIC);

            // 'private' is the old name for 'pending'.
            if ($status === Item::STATUS_LEGACY_PRIVATE) {
                $status = Item::STATUS_PENDING;
            }

            if (!in_array($status, Item::ALL_STATUSES, true)) {
                return response()->json([
                    'message' => 'Invalid status. Allowed values: ' . implode(', ', Item::ALL_STATUSES),
                ], 422);
            }

            $isOwnListings = in_array($status, [Item::STATUS_PENDING, Item::STATUS_REJECTED], true);

            if ($isOwnListings) {
                // A pending listing belongs to its seller and to Admin, and to
                // nobody else - it is not part of any public catalog.
                if (!$user) {
                    return response()->json([
                        'message' => 'Authentication required to view your own listings',
                    ], 401);
                }

                $statuses = $status === Item::STATUS_PENDING
                    ? [Item::STATUS_PENDING, Item::STATUS_LEGACY_PRIVATE]
                    : [$status];

                $query->whereIn('status', $statuses);

                if (!$user->isAdmin()) {
                    $query->where('seller_id', $user->user_id);
                }
            } else {
                $query->where('status', $status);
            }

            if ($request->filled('category_id')) {
                $query->where('category_id', $request->query('category_id'));
            }

            if ($request->filled('category')) {
                $category = $request->query('category');
                $query->whereHas('category', fn ($q) => $q->where('name', 'like', "%{$category}%"));
            }

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Price filtering and sorting run on the buyer-facing peso price
            // for catalog views, and on the asking price for a seller's own
            // list. The old code filtered on price_points even for public
            // items, whose displayed price came from a different column.
            $priceColumn = $isOwnListings ? 'seller_asking_price' : 'public_price';

            if ($request->filled('price_min')) {
                $min = $this->parsePrice($request->query('price_min'));
                if ($min !== null) {
                    $query->where($priceColumn, '>=', $min->toDecimalString());
                }
            }

            if ($request->filled('price_max')) {
                $max = $this->parsePrice($request->query('price_max'));
                if ($max !== null) {
                    $query->where($priceColumn, '<=', $max->toDecimalString());
                }
            }

            if ($request->filled('seller_id')) {
                $query->where('seller_id', $request->query('seller_id'));
            }

            match ($request->query('sort')) {
                'price_asc' => $query->orderBy($priceColumn, 'asc'),
                'price_desc' => $query->orderBy($priceColumn, 'desc'),
                'oldest' => $query->orderBy('created_at', 'asc'),
                default => $query->orderBy('created_at', 'desc'),
            };

            $items = $query->get()->map(function (Item $item) use ($user) {
                $isOwner = $user && $item->seller_id === $user->user_id;

                // A seller looking at their own unpublished listing sees their
                // asking price and no reward figure; everyone else sees the
                // catalog view.
                return $isOwner && !$item->isPurchasable()
                    ? ItemPresenter::forSeller($item)
                    : ItemPresenter::forBuyer($item);
            });

            return response()->json([
                'message' => 'Items retrieved successfully',
                'data' => $items,
                'count' => $items->count(),
                'filters' => [
                    'status' => $status,
                    'category_id' => $request->query('category_id'),
                    'seller_id' => $request->query('seller_id'),
                    'sort' => $request->query('sort', 'newest'),
                ],
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
     * Get item details.
     * GET /api/items/{item_id}
     */
    public function getItemDetails(Request $request, $itemId)
    {
        try {
            $item = Item::with([
                'seller' => fn ($q) => $q->select('user_id', 'email'),
                'photos' => fn ($q) => $q->select('photo_id', 'item_id', 'photo_url'),
            ])->where('item_id', $itemId)->first();

            if (!$item) {
                return response()->json(['message' => 'Item not found'], 404);
            }

            $user = $this->resolveOptionalUser($request);

            if ($user?->isAdmin()) {
                $payload = ItemPresenter::forAdmin($item);
            } elseif ($user && $item->seller_id === $user->user_id && !$item->isPurchasable()) {
                $payload = ItemPresenter::forSeller($item);
            } else {
                // An unpublished item is not browsable by other students.
                if (!in_array($item->status, [Item::STATUS_PUBLIC, Item::STATUS_RESERVED, Item::STATUS_SOLD], true)) {
                    return response()->json(['message' => 'Item not found'], 404);
                }

                $payload = ItemPresenter::forBuyer($item);
            }

            return response()->json([
                'message' => 'Item details retrieved successfully',
                'data' => $payload,
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
     * Update an item.
     * PUT /api/items/{item_id}
     *
     * A seller may correct the details of a listing that is still pending.
     * `status` is deliberately not accepted: publishing is an Admin action
     * gated on physical turnover. The previous version of this endpoint
     * accepted `status` and let a student set their own item to `public`.
     */
    public function updateItem(Request $request, $itemId)
    {
        try {
            $item = Item::where('item_id', $itemId)->first();

            if (!$item) {
                return response()->json(['message' => 'Item not found'], 404);
            }

            if ($item->seller_id !== $request->user()->user_id) {
                return response()->json([
                    'message' => 'Unauthorized. You can only update your own items.',
                ], 403);
            }

            if (!$item->isPending()) {
                return response()->json([
                    'message' => 'This listing has already been accepted by the admin and can no longer be edited.',
                ], 409);
            }

            $validated = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['sometimes', 'string', 'max:1000'],
                'category_id' => ['sometimes', 'integer', 'exists:categories,category_id'],
                'seller_asking_price' => ['sometimes', 'string', 'max:20'],
                'price_points' => ['sometimes', 'numeric', 'min:0'],
            ]);

            $updates = array_intersect_key($validated, array_flip(['title', 'description', 'category_id']));

            $rawPrice = $validated['seller_asking_price'] ?? $validated['price_points'] ?? null;

            if ($rawPrice !== null) {
                $price = $this->parsePrice($rawPrice);

                if ($price === null || $price->isNegative()) {
                    return response()->json([
                        'message' => 'Invalid asking price',
                        'errors' => ['seller_asking_price' => ['Enter a valid peso amount.']],
                    ], 422);
                }

                $updates['seller_asking_price'] = $price->toDecimalString();
                $updates['price_points'] = intdiv($price->centavos(), 100);
            }

            if ($updates !== []) {
                $item->update($updates);
            }

            return response()->json([
                'message' => 'Item updated successfully',
                'data' => ItemPresenter::forSeller($item->fresh(['photos', 'seller'])),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error updating item', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to update item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an item.
     * DELETE /api/items/{item_id}
     */
    public function deleteItem(Request $request, $itemId)
    {
        try {
            $item = Item::where('item_id', $itemId)->first();

            if (!$item) {
                return response()->json(['message' => 'Item not found'], 404);
            }

            if ($item->seller_id !== $request->user()->user_id) {
                return response()->json([
                    'message' => 'Unauthorized. You can only delete your own items.',
                ], 403);
            }

            // Once Admin has taken physical possession the listing is part of
            // the shop's inventory and its history must survive.
            if (!$item->isPending()) {
                return response()->json([
                    'message' => 'This listing can no longer be deleted because the admin has already accepted it.',
                ], 409);
            }

            Log::info('Deleting item', ['item_id' => $itemId]);

            ItemPhoto::where('item_id', $itemId)->delete();
            $item->delete();

            return response()->json(['message' => 'Item deleted successfully'], 200);

        } catch (\Exception $e) {
            Log::error('Error deleting item', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to delete item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Parse a client-supplied peso amount, returning null when it is not a
     * well-formed money value. This captures the buyer's stated intent only -
     * every total is recomputed server-side.
     */
    private function parsePrice(string|int|float|null $raw): ?Money
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return Money::fromPesos(is_string($raw) ? trim($raw) : $raw);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Resolve the user behind an optional Bearer token.
     *
     * These endpoints are public but behave differently when a token is
     * present, so authentication is attempted rather than required.
     */
    private function resolveOptionalUser(Request $request)
    {
        if ($request->user()) {
            return $request->user();
        }

        if (!$request->bearerToken()) {
            return null;
        }

        try {
            $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());

            return $token?->tokenable;
        } catch (\Exception $e) {
            Log::warning('Invalid token provided', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
