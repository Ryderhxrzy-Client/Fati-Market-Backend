<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Point;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
 * Every row now names the person it belongs to rather than only describing
 * them. It used to carry a display name and nothing else, so both clients drew
 * the same anonymous icon beside every line and a student registering looked
 * exactly like the store handing an item over. A row now carries:
 *
 *   - the actor: who did it, with their id and their profile photo, read from
 *     `acquired_by`, `published_by`, `completed_by` and the rest rather than
 *     assumed to be the store;
 *   - the subject: the other person in the event, where there is one - the
 *     buyer an item was handed to, the seller it was taken from;
 *   - details: the labelled facts behind the sentence, so opening one row
 *     shows what it was actually about.
 *
 * The original six fields are untouched, so an older build reading this feed
 * keeps working.
 */
class ActivityController extends Controller
{
    /** How far back a single page of the feed reaches. */
    private const DEFAULT_LIMIT = 100;

    /** The store account, resolved once per request for the events it owns. */
    private ?User $store = null;

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
                    'user' => $e['actor']['name'],
                    'description' => $e['description'],
                    'resource_type' => $e['resource_type'],
                    'resource_id' => $e['resource_id'],
                    'timestamp' => $e['at']->toDateTimeString(),

                    // Who it was. `user` above stays a plain name for older
                    // builds; these are what a face can be drawn from.
                    'user_id' => $e['actor']['user_id'],
                    'user_photo' => $e['actor']['photo'],
                    'user_role' => $e['actor']['role'],
                    'user_email' => $e['actor']['email'],

                    // The other person in the event, when there is one.
                    'subject' => $e['subject'],

                    // The labelled facts behind the sentence.
                    'details' => $e['details'],
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

    /** Listings: offered, received into stock, published. */
    private function itemEvents(int $limit): array
    {
        $events = [];

        $items = Item::with(['seller.studentInfo'])->latest('updated_at')->limit($limit)->get();

        // The admins who received and published them, in one query rather
        // than one for every row.
        $staff = $this->peopleByIds($items->flatMap(
            fn (Item $item) => [$item->acquired_by, $item->published_by]
        ));

        foreach ($items as $item) {
            $seller = $this->person($item->seller);
            $asking = $this->peso($item->seller_asking_price);
            $agreed = $this->peso($item->acquisition_price);
            $selling = $this->peso($item->public_price);

            $facts = array_values(array_filter([
                $this->fact('Item', $item->title),
                $this->fact('Seller', $seller['name']),
                $this->fact('Status', ucfirst((string) $item->status)),
                $this->fact('Asking price', $asking),
                $this->fact('Agreed price', $agreed),
                $this->fact('Selling price', $selling),
            ]));

            $events[] = $this->event(
                'create',
                $seller,
                "Listed \"{$item->title}\" for review",
                'item',
                $item->item_id,
                $item->created_at,
                $facts,
            );

            if ($item->acquired_at !== null) {
                $events[] = $this->event(
                    'update',
                    $staff[$item->acquired_by] ?? $this->storePerson(),
                    "Received \"{$item->title}\" into stock",
                    'item',
                    $item->item_id,
                    $item->acquired_at,
                    $facts,
                    $seller,
                );
            }

            if ($item->published_at !== null) {
                $price = $selling === null ? '' : " at {$selling}";

                $events[] = $this->event(
                    'update',
                    $staff[$item->published_by] ?? $this->storePerson(),
                    "Published \"{$item->title}\"{$price}",
                    'item',
                    $item->item_id,
                    $item->published_at,
                    $facts,
                    $seller,
                );
            }
        }

        return $events;
    }

    /** Buyer orders: placed, completed, cancelled. */
    private function orderEvents(int $limit): array
    {
        $events = [];

        $orders = Transaction::with(['buyer.studentInfo', 'item'])
            ->buyerOrders()
            ->latest('transaction_date')
            ->limit($limit)
            ->get();

        $staff = $this->peopleByIds($orders->flatMap(
            fn (Transaction $order) => [$order->completed_by, $order->cancelled_by]
        ));

        foreach ($orders as $order) {
            $buyer = $this->person($order->buyer);
            $title = $order->item?->title ?? "item #{$order->item_id}";
            $amount = '₱' . $order->amountDueMoney()->toFormattedString();

            $facts = array_values(array_filter([
                $this->fact('Item', $title),
                $this->fact('Buyer', $buyer['name']),
                $this->fact('Amount due', $amount),
                $this->fact('Points used', $order->points_used > 0 ? (string) $order->points_used : null),
                $this->fact('Payment', $order->payment_method === null ? null : ucfirst((string) $order->payment_method)),
                $this->fact('Payment status', $this->label($order->payment_status)),
                $this->fact('Order status', $this->label($order->status)),
                $this->fact('Reason', $order->cancel_reason),
            ]));

            $events[] = $this->event(
                'purchase',
                $buyer,
                "Ordered \"{$title}\" for {$amount}",
                'order',
                $order->transaction_id,
                $order->transaction_date,
                $facts,
            );

            if ($order->completed_at !== null) {
                $events[] = $this->event(
                    'purchase',
                    $staff[$order->completed_by] ?? $this->storePerson(),
                    "Handed \"{$title}\" over to {$buyer['name']}",
                    'order',
                    $order->transaction_id,
                    $order->completed_at,
                    $facts,
                    $buyer,
                );
            }

            if ($order->cancelled_at !== null) {
                // A buyer can call their own order off, so this is no longer
                // assumed to be the store's doing.
                $events[] = $this->event(
                    'delete',
                    $staff[$order->cancelled_by] ?? $this->storePerson(),
                    "Cancelled the order for \"{$title}\"",
                    'order',
                    $order->transaction_id,
                    $order->cancelled_at,
                    $facts,
                    $buyer,
                );
            }
        }

        return $events;
    }

    /** The loyalty ledger, which is its own audit trail already. */
    private function pointEvents(int $limit): array
    {
        return Point::with('user.studentInfo')
            ->whereIn('type', Point::CURRENT_TYPES)
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(function (Point $point) {
                $change = $point->points_change;
                $sign = $change > 0 ? '+' : '';
                $person = $this->person($point->user);

                return $this->event(
                    'update',
                    $person,
                    $point->reason ?: "{$sign}{$change} point(s)",
                    'points',
                    $point->point_id,
                    $point->created_at,
                    array_values(array_filter([
                        $this->fact('Person', $person['name']),
                        $this->fact('Change', "{$sign}{$change} point(s)"),
                        $this->fact('Type', ucfirst((string) $point->type)),
                        $this->fact('Reason', $point->reason),
                    ])),
                );
            })
            ->all();
    }

    /** Accounts joining the marketplace. */
    private function userEvents(int $limit): array
    {
        return User::with('studentInfo')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(function (User $user) {
                $person = $this->person($user);

                return $this->event(
                    'create',
                    $person,
                    $user->role === User::ROLE_ADMIN
                        ? 'Admin account created'
                        : 'Registered a student account',
                    'user',
                    $user->user_id,
                    $user->created_at,
                    array_values(array_filter([
                        $this->fact('Name', $person['name']),
                        $this->fact('Email', $user->email),
                        $this->fact('Role', ucfirst((string) $user->role)),
                        $this->fact('Account', $user->is_active ? 'Active' : 'Inactive'),
                    ])),
                );
            })
            ->all();
    }

    /**
     * The people behind a set of ids, keyed by id.
     *
     * @param  \Illuminate\Support\Collection<int, int|null>  $ids
     * @return array<int, array{user_id: ?int, name: string, photo: ?string, role: ?string, email: ?string}>
     */
    private function peopleByIds(Collection $ids): array
    {
        $ids = $ids->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::with('studentInfo')
            ->whereIn('user_id', $ids)
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->user_id => $this->person($user)])
            ->all();
    }

    /**
     * One person, as a row can show them.
     *
     * The photo is the same `profile_picture` the chat and the student list
     * use, so a face in the feed is the face everywhere else.
     *
     * @return array{user_id: ?int, name: string, photo: ?string, role: ?string, email: ?string}
     */
    private function person(?User $user): array
    {
        if ($user === null) {
            return ['user_id' => null, 'name' => 'Unknown User', 'photo' => null, 'role' => null, 'email' => null];
        }

        $info = $user->studentInfo;
        $name = trim(($info?->first_name ?? '') . ' ' . ($info?->last_name ?? ''));

        return [
            'user_id' => $user->user_id,
            'name' => $name !== '' ? $name : (string) str($user->email)->before('@'),
            'photo' => $info?->profile_picture,
            'role' => $user->role,
            'email' => $user->email,
        ];
    }

    /**
     * The store itself, for the rows written before anyone was recorded doing
     * them. Its real account is used when there is one, so even those rows
     * carry a face.
     *
     * @return array{user_id: ?int, name: string, photo: ?string, role: ?string, email: ?string}
     */
    private function storePerson(): array
    {
        $this->store ??= User::with('studentInfo')
            ->where('role', User::ROLE_ADMIN)
            ->orderBy('user_id')
            ->first();

        if ($this->store === null) {
            return ['user_id' => null, 'name' => 'Ofelia Store', 'photo' => null, 'role' => User::ROLE_ADMIN, 'email' => null];
        }

        return array_merge($this->person($this->store), ['name' => 'Ofelia Store']);
    }

    /** A peso figure for the details, or null when there is no figure. */
    private function peso(mixed $amount): ?string
    {
        return $amount === null ? null : '₱' . Money::fromPesos($amount)->toFormattedString();
    }

    /** "pending_payment" reads as "Pending payment". */
    private function label(?string $value): ?string
    {
        return $value === null ? null : ucfirst(str_replace('_', ' ', $value));
    }

    /**
     * One labelled fact, or null when there is nothing to say.
     *
     * @return array{label: string, value: string}|null
     */
    private function fact(string $label, ?string $value): ?array
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : ['label' => $label, 'value' => $value];
    }

    /**
     * @param  array{user_id: ?int, name: string, photo: ?string, role: ?string, email: ?string}  $actor
     * @param  array<int, array{label: string, value: string}>  $details
     * @param  array{user_id: ?int, name: string, photo: ?string, role: ?string, email: ?string}|null  $subject
     */
    private function event(
        string $action,
        array $actor,
        string $description,
        string $resourceType,
        int $resourceId,
        $at,
        array $details = [],
        ?array $subject = null,
    ): array {
        return [
            'action' => $action,
            'actor' => $actor,
            'subject' => $subject,
            'details' => $details,
            'description' => $description,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'at' => $at === null ? null : \Illuminate\Support\Carbon::parse($at),
        ];
    }
}
