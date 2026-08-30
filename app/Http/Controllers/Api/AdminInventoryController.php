<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemPresenter;
use App\Models\Item;
use App\Services\ItemLifecycleService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Admin inventory: the offers page, negotiation outcome, physical turnover,
 * seller payout and publication.
 *
 * These act on the same `items` records the students upload and the buyers
 * browse - there is one inventory, not a separate admin one. Every route here
 * sits behind the `admin` middleware, and every figure is recomputed from the
 * database rather than taken from the request.
 */
class AdminInventoryController extends Controller
{
    public function __construct(private readonly ItemLifecycleService $lifecycle)
    {
    }

    /**
     * All items, any status.
     * GET /api/admin/items?status=pending
     */
    public function index(Request $request)
    {
        try {
            $query = Item::with([
                'seller' => fn ($q) => $q->select('user_id', 'email'),
                'photos' => fn ($q) => $q->select('photo_id', 'item_id', 'photo_url'),
            ]);

            if ($request->filled('status')) {
                $status = $request->query('status');

                if ($status === Item::STATUS_LEGACY_PRIVATE) {
                    $status = Item::STATUS_PENDING;
                }

                if (!in_array($status, Item::ALL_STATUSES, true)) {
                    return response()->json([
                        'message' => 'Invalid status. Allowed values: ' . implode(', ', Item::ALL_STATUSES),
                    ], 422);
                }

                // Rows migrated from the old schema may still read 'private'.
                $query->whereIn(
                    'status',
                    $status === Item::STATUS_PENDING
                        ? [Item::STATUS_PENDING, Item::STATUS_LEGACY_PRIVATE]
                        : [$status]
                );
            }

            if ($request->filled('category_id')) {
                $query->where('category_id', $request->query('category_id'));
            }

            if ($request->filled('seller_id')) {
                $query->where('seller_id', $request->query('seller_id'));
            }

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            if ($request->boolean('unpaid_sellers_only')) {
                $query->where('seller_payout_status', Item::PAYOUT_UNPAID)
                    ->whereNotNull('acquired_at');
            }

            $items = $query->orderBy('created_at', 'desc')->get()
                ->map(fn (Item $item) => ItemPresenter::forAdmin($item));

            return response()->json([
                'message' => 'Admin: Items retrieved successfully',
                'data' => $items,
                'count' => $items->count(),
                'filters' => [
                    'status' => $request->query('status', 'all'),
                    'category_id' => $request->query('category_id'),
                    'seller_id' => $request->query('seller_id'),
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting admin items', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to retrieve items', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * The offers page: everything awaiting a decision.
     * GET /api/admin/items/pending
     */
    public function pending(Request $request)
    {
        $items = Item::with([
            'seller' => fn ($q) => $q->select('user_id', 'email'),
            'photos' => fn ($q) => $q->select('photo_id', 'item_id', 'photo_url'),
        ])
            ->whereIn('status', [Item::STATUS_PENDING, Item::STATUS_LEGACY_PRIVATE])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Item $item) => ItemPresenter::forAdmin($item));

        return response()->json([
            'message' => 'Pending offers retrieved successfully',
            'data' => $items,
            'count' => $items->count(),
        ], 200);
    }

    /**
     * Record the negotiated acquisition price agreed in chat.
     * POST /api/admin/items/{item_id}/acquisition-price
     */
    public function setAcquisitionPrice(Request $request, $itemId)
    {
        $request->validate(['acquisition_price' => ['required', 'string', 'max:20']]);

        return $this->withItem($itemId, function (Item $item) use ($request) {
            $price = $this->parsePrice($request->input('acquisition_price'));

            if ($price === null || $price->isNegative()) {
                return $this->invalidAmount('acquisition_price');
            }

            $item = $this->lifecycle->recordAcquisitionPrice($item, $price);

            return response()->json([
                'message' => 'Acquisition price recorded',
                'data' => ItemPresenter::forAdmin($item),
            ], 200);
        });
    }

    /**
     * Record the agreed meeting / physical turnover schedule.
     * POST /api/admin/items/{item_id}/meetup
     */
    public function setMeetupSchedule(Request $request, $itemId)
    {
        $request->validate(['meetup_schedule' => ['nullable', 'date']]);

        return $this->withItem($itemId, function (Item $item) use ($request) {
            $item = $this->lifecycle->setMeetupSchedule($item, $request->input('meetup_schedule'));

            return response()->json([
                'message' => 'Meet-up schedule saved',
                'data' => ItemPresenter::forAdmin($item),
            ], 200);
        });
    }

    /**
     * Ofelia/Admin has physically received and verified the item.
     * POST /api/admin/items/{item_id}/verify-turnover
     *
     * Records who verified it and when, and the cash amount payable to the
     * student seller. The payout is marked separately, once cash changes hands.
     */
    public function verifyTurnover(Request $request, $itemId)
    {
        $request->validate([
            'seller_payout_amount' => ['nullable', 'string', 'max:20'],
            'acquisition_price' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->withItem($itemId, function (Item $item) use ($request) {
            // Allow the acquisition price to be settled in the same step.
            if ($request->filled('acquisition_price')) {
                $acquisition = $this->parsePrice($request->input('acquisition_price'));

                if ($acquisition === null || $acquisition->isNegative()) {
                    return $this->invalidAmount('acquisition_price');
                }

                $item = $this->lifecycle->recordAcquisitionPrice($item, $acquisition);
            }

            $payout = null;

            if ($request->filled('seller_payout_amount')) {
                $payout = $this->parsePrice($request->input('seller_payout_amount'));

                if ($payout === null || $payout->isNegative()) {
                    return $this->invalidAmount('seller_payout_amount');
                }
            }

            $item = $this->lifecycle->verifyTurnover(
                $item,
                $request->user(),
                $payout,
                $request->input('notes')
            );

            return response()->json([
                'message' => 'Item received and verified',
                'data' => ItemPresenter::forAdmin($item),
            ], 200);
        });
    }

    /**
     * Mark the student seller as paid in cash.
     * POST /api/admin/items/{item_id}/seller-payout
     *
     * This is the seller side of the business and is entirely separate from
     * buyer reward points. It replaces the old "Send Points & Finalize" flow,
     * which paid sellers in wallet points.
     */
    public function recordSellerPayout(Request $request, $itemId)
    {
        $request->validate(['amount' => ['nullable', 'string', 'max:20']]);

        return $this->withItem($itemId, function (Item $item) use ($request) {
            $amount = null;

            if ($request->filled('amount')) {
                $amount = $this->parsePrice($request->input('amount'));

                if ($amount === null || $amount->isNegative()) {
                    return $this->invalidAmount('amount');
                }
            }

            $item = $this->lifecycle->recordSellerPayout($item, $request->user(), $amount);

            return response()->json([
                'message' => 'Seller payout recorded',
                'data' => ItemPresenter::forAdmin($item),
            ], 200);
        });
    }

    /**
     * Preview what publishing at a given price would mean.
     * GET /api/admin/items/{item_id}/publish-preview?public_price=250
     *
     * Produced by the same code path that publishes, so the reward points the
     * admin sees before publishing are exactly the ones that get stored.
     */
    public function publishPreview(Request $request, $itemId)
    {
        $request->validate(['public_price' => ['required', 'string', 'max:20']]);

        return $this->withItem($itemId, function (Item $item) use ($request) {
            $price = $this->parsePrice($request->query('public_price'));

            if ($price === null || $price->isNegative()) {
                return $this->invalidAmount('public_price');
            }

            $preview = $this->lifecycle->previewPublication($item, $price);

            return response()->json([
                'message' => 'Publication preview',
                'data' => [
                    'item_id' => $item->item_id,
                    'seller_asking_price' => $item->askingPrice()->toDecimalString(),
                    'acquisition_price' => $preview['acquisition_price']?->toDecimalString(),
                    'public_price' => $preview['public_price']->toDecimalString(),
                    'markup' => $preview['markup']?->toDecimalString(),
                    'reward_points' => $preview['reward_points'],
                    'reward_label' => "Buyer earns {$preview['reward_points']} point"
                        . ($preview['reward_points'] === 1 ? '' : 's'),
                    'can_publish' => $preview['can_publish'],
                    'blockers' => $preview['blockers'],
                ],
            ], 200);
        });
    }

    /**
     * Publish to the buyer catalog at a peso price.
     * POST /api/admin/items/{item_id}/publish
     */
    public function publish(Request $request, $itemId)
    {
        $request->validate(['public_price' => ['required', 'string', 'max:20']]);

        return $this->withItem($itemId, function (Item $item) use ($request) {
            $price = $this->parsePrice($request->input('public_price'));

            if ($price === null || $price->isNegative()) {
                return $this->invalidAmount('public_price');
            }

            $item = $this->lifecycle->publish($item, $price, $request->user());

            return response()->json([
                'message' => 'Item published successfully',
                'data' => ItemPresenter::forAdmin($item),
            ], 200);
        });
    }

    /** POST /api/admin/items/{item_id}/unpublish */
    public function unpublish(Request $request, $itemId)
    {
        return $this->withItem($itemId, function (Item $item) {
            $item = $this->lifecycle->unpublish($item);

            return response()->json([
                'message' => 'Item removed from the catalog',
                'data' => ItemPresenter::forAdmin($item),
            ], 200);
        });
    }

    /** POST /api/admin/items/{item_id}/reject */
    public function reject(Request $request, $itemId)
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->withItem($itemId, function (Item $item) use ($request) {
            $item = $this->lifecycle->reject($item, $request->input('reason'));

            return response()->json([
                'message' => 'Item rejected',
                'data' => ItemPresenter::forAdmin($item),
            ], 200);
        });
    }

    /**
     * Update an item's descriptive fields.
     * PUT /api/admin/items/{item_id}
     *
     * Pricing and publication are deliberately not settable here - they have
     * dedicated, gated endpoints. For older admin app builds that still post
     * `status` and `markup_points`, those are routed through the same gated
     * publish path rather than written straight to the row, so an item can
     * never reach the catalog without verified turnover.
     */
    public function update(Request $request, $itemId)
    {
        $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:1000'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,category_id'],
            'status' => ['sometimes', 'string'],
            'markup_points' => ['sometimes', 'numeric', 'min:0'],
            'public_price' => ['sometimes', 'string', 'max:20'],
        ]);

        return $this->withItem($itemId, function (Item $item) use ($request) {
            $updates = $request->only(['title', 'description', 'category_id']);
            $updates = array_filter($updates, fn ($value) => $value !== null);

            if ($updates !== []) {
                $item->update($updates);
            }

            $requestedStatus = $request->input('status');

            if ($requestedStatus === Item::STATUS_LEGACY_PRIVATE) {
                $requestedStatus = Item::STATUS_PENDING;
            }

            // Legacy builds send the peso selling price in `markup_points`.
            $rawPrice = $request->input('public_price') ?? $request->input('markup_points');

            if ($requestedStatus === Item::STATUS_PUBLIC) {
                $price = $rawPrice !== null ? $this->parsePrice($rawPrice) : $item->publicPrice();

                if ($price === null || !$price->isPositive()) {
                    return response()->json([
                        'message' => 'A valid public selling price is required to publish this item.',
                    ], 422);
                }

                $item = $this->lifecycle->publish($item, $price, $request->user());
            } elseif ($requestedStatus === Item::STATUS_REJECTED) {
                $item = $this->lifecycle->reject($item, $request->input('reason', 'Rejected by admin'));
            } elseif ($requestedStatus !== null && $requestedStatus !== $item->status) {
                if (!in_array($requestedStatus, Item::ALL_STATUSES, true)) {
                    return response()->json([
                        'message' => 'Invalid status. Allowed values: ' . implode(', ', Item::ALL_STATUSES),
                    ], 422);
                }

                // `acquired` and `sold` are reached through turnover
                // verification and checkout completion respectively.
                if (in_array($requestedStatus, [Item::STATUS_ACQUIRED, Item::STATUS_SOLD], true)) {
                    return response()->json([
                        'message' => $requestedStatus === Item::STATUS_ACQUIRED
                            ? 'Use the verify-turnover action to mark an item as acquired.'
                            : 'An item becomes sold when its transaction is completed.',
                    ], 422);
                }

                $item->update(['status' => $requestedStatus]);
            } elseif ($rawPrice !== null && $item->status === Item::STATUS_PUBLIC) {
                $price = $this->parsePrice($rawPrice);

                if ($price === null || !$price->isPositive()) {
                    return $this->invalidAmount('public_price');
                }

                $item = $this->lifecycle->updatePublicPrice($item, $price, $request->user());
            }

            return response()->json([
                'message' => 'Admin: Item updated successfully',
                'data' => ItemPresenter::forAdmin($item->fresh(['photos', 'seller'])),
                'updated_by_admin' => $request->user()->user_id,
            ], 200);
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Load the item, run the action, and turn rule violations into 422s. */
    private function withItem($itemId, callable $action)
    {
        $item = Item::with(['photos', 'seller'])->where('item_id', $itemId)->first();

        if (!$item) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        try {
            return $action($item);
        } catch (RuntimeException $e) {
            // Business-rule refusals, e.g. publishing before turnover.
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Admin inventory action failed', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'The action could not be completed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

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

    private function invalidAmount(string $field)
    {
        return response()->json([
            'message' => 'Invalid amount',
            'errors' => [$field => ['Enter a valid peso amount, for example 250 or 249.50.']],
        ], 422);
    }
}
