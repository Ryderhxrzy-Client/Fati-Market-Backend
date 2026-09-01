<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Point;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * What has been happening in the store.
 *
 * Deliberately derived rather than logged. A new audit table would have been
 * empty on the day it shipped and would have said nothing about anything that
 * happened before it - which is exactly the complaint this answers. Every event
 * here is already recorded as a timestamp on a row that had to be written
 * anyway: an item's `acquired_at`, an order's `completed_at`, a ledger entry's
 * `created_at`. Reading them back costs nothing and reaches all the way to the
 * store's first day.
 *
 * The row shape matches what the admin website already renders - action, user,
 * description, timestamp, resource_type - so both clients read one feed.
 */
class ActivityController extends Controller
{
    /** How far back a single page of the feed reaches. */
    private const DEFAULT_LIMIT = 100;

    /**
     * GET /api/admin/activity
     *
     * Optional `limit` (1-500) and `type` (item / order / points / user).
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'type' => ['nullable', 'string', 'in:item,order,points,user'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $type = $validated['type'] ?? null;

        try {
            $events = collect();

            if ($type === null || $type === 'item') {
                $events = $events->merge($this->itemEvents($limit));
            }

            if ($type === null || $type === 'order') {
                $events = $events->merge($this->orderEvents($limit));
            }

            if ($type === null || $type === 'points') {
                $events = $events->merge($this->pointEvents($limit));
            }

            if ($type === null || $type === 'user') {
                $events = $events->merge($this->userEvents($limit));
            }

            // One clock across four tables, newest first.
            $feed = $events
                ->filter(fn (array $e) => $e['at'] !== null)
                ->sortByDesc(fn (array $e) => $e['at']->getTimestamp())
                ->take($limit)
                ->map(fn (array $e) => [
                    'action' => $e['action'],
                    'user' => $e['user'],
                    'description' => $e['description'],
                    'resource_type' => $e['resource_type'],
                    'resource_id' => $e['resource_id'],
                    'timestamp' => $e['at']->toDateTimeString(),
                ])
                ->values();

            return response()->json([
                'message' => 'Activity retrieved successfully',
                'data' => $feed,
                'count' => $feed->count(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Error building the activity feed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Failed to retrieve activity',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** Listings: offered, received into stock, published, sold. */
    private function itemEvents(int $limit): array
    {
        $events = [];

        $items = Item::with('seller')->latest('updated_at')->limit($limit)->get();

        foreach ($items as $item) {
            $seller = $this->nameFor($item->seller);

            $events[] = $this->event(
                'create',
                $seller,
                "Listed \"{$item->title}\" for review",
                'item',
                $item->item_id,
                $item->created_at,
            );

            if ($item->acquired_at !== null) {
                $events[] = $this->event(
                    'update',
                    'Ofelia Store',
                    "Received \"{$item->title}\" into stock",
                    'item',
                    $item->item_id,
                    $item->acquired_at,
                );
            }

            if ($item->published_at !== null) {
                $price = $item->public_price === null
                    ? ''
                    : ' at ₱' . Money::fromPesos($item->public_price)->toFormattedString();

                $events[] = $this->event(
                    'update',
                    'Ofelia Store',
                    "Published \"{$item->title}\"{$price}",
                    'item',
                    $item->item_id,
                    $item->published_at,
                );
            }
        }

        return $events;
    }

    /** Buyer orders: placed, completed, cancelled. */
    private function orderEvents(int $limit): array
    {
        $events = [];

        $orders = Transaction::with(['buyer', 'item'])
            ->buyerOrders()
            ->latest('transaction_date')
            ->limit($limit)
            ->get();

        foreach ($orders as $order) {
            $buyer = $this->nameFor($order->buyer);
            $title = $order->item?->title ?? "item #{$order->item_id}";
            $amount = '₱' . $order->amountDueMoney()->toFormattedString();

            $events[] = $this->event(
                'purchase',
                $buyer,
                "Ordered \"{$title}\" for {$amount}",
                'order',
                $order->transaction_id,
                $order->transaction_date,
            );

            if ($order->completed_at !== null) {
                $events[] = $this->event(
                    'purchase',
                    'Ofelia Store',
                    "Handed \"{$title}\" over to {$buyer}",
                    'order',
                    $order->transaction_id,
                    $order->completed_at,
                );
            }

            if ($order->cancelled_at !== null) {
                $events[] = $this->event(
                    'delete',
                    'Ofelia Store',
                    "Cancelled the order for \"{$title}\"",
                    'order',
                    $order->transaction_id,
                    $order->cancelled_at,
                );
            }
        }

        return $events;
    }

    /** The loyalty ledger, which is its own audit trail already. */
    private function pointEvents(int $limit): array
    {
        return Point::with('user')
            ->whereIn('type', Point::CURRENT_TYPES)
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(function (Point $point) {
                $change = $point->points_change;
                $sign = $change > 0 ? '+' : '';

                return $this->event(
                    'update',
                    $this->nameFor($point->user),
                    $point->reason ?: "{$sign}{$change} point(s)",
                    'points',
                    $point->point_id,
                    $point->created_at,
                );
            })
            ->all();
    }

    /** Accounts joining the marketplace. */
    private function userEvents(int $limit): array
    {
        return User::latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (User $user) => $this->event(
                'create',
                $this->nameFor($user),
                $user->role === User::ROLE_ADMIN
                    ? 'Admin account created'
                    : 'Registered a student account',
                'user',
                $user->user_id,
                $user->created_at,
            ))
            ->all();
    }

    /**
     * A person's everyday name, falling back to the local part of their email.
     *
     * "Unknown User" is what the website prints for a null, and a row nobody
     * can be attached to is not worth showing as activity.
     */
    private function nameFor(?User $user): string
    {
        if ($user === null) {
            return 'Unknown User';
        }

        $info = $user->studentInfo;
        $name = trim(($info?->first_name ?? '') . ' ' . ($info?->last_name ?? ''));

        return $name !== '' ? $name : (string) str($user->email)->before('@');
    }

    /** @return array{action: string, user: string, description: string, resource_type: string, resource_id: int, at: ?\Carbon\CarbonInterface} */
    private function event(
        string $action,
        string $user,
        string $description,
        string $resourceType,
        int $resourceId,
        $at,
    ): array {
        return [
            'action' => $action,
            'user' => $user,
            'description' => $description,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'at' => $at === null ? null : \Illuminate\Support\Carbon::parse($at),
        ];
    }
}
