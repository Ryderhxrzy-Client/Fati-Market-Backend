<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionPresenter;
use App\Models\Item;
use App\Models\Point;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PhotoUploader;
use App\Services\PointsLedger;
use App\Support\OrderQr;
use App\Support\LoyaltyRules;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Buyer order history, Admin transaction management, the points ledger and the
 * admin reports.
 *
 * Two things that used to live together are now firmly apart: buyer reward
 * points, credited only when Admin completes an order, and seller cash payouts,
 * which are recorded against the item in AdminInventoryController.
 */
class TransactionController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly PointsLedger $ledger,
        private readonly PhotoUploader $uploader,
    ) {
    }

    // ── Buyer ────────────────────────────────────────────────────────────

    /**
     * The signed-in user's orders.
     * GET /api/transactions
     */
    public function getUserTransactions(Request $request)
    {
        try {
            $user = $request->user();

            $transactions = Transaction::with(['item.photos', 'buyer', 'seller'])
                ->buyerOrders()
                ->where(function ($query) use ($user) {
                    $query->where('buyer_id', $user->user_id)
                        ->orWhere('seller_id', $user->user_id);
                })
                ->orderBy('transaction_date', 'desc')
                ->get()
                ->map(fn (Transaction $t) => TransactionPresenter::forBuyer($t));

            return response()->json([
                'message' => 'User transactions retrieved successfully',
                'data' => $transactions,
                'count' => $transactions->count(),
                'wallet_points' => $user->wallet_points,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting user transactions', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to retrieve transactions'], 500);
        }
    }

    /**
     * The receipt for one order.
     * GET /api/transactions/{transaction_id}/receipt
     *
     * Serves as the buyer's proof of transaction, whether they paid by GCash
     * or cash at the store. Only the buyer and Admin may read it, and only an
     * order whose payment has actually been verified produces one.
     */
    public function getReceipt(Request $request, $transactionId)
    {
        $user = $request->user();

        $transaction = Transaction::with(['item.photos', 'buyer.studentInfo', 'seller'])
            ->buyerOrders()
            ->where('transaction_id', $transactionId)
            ->first();

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        if ($transaction->buyer_id !== $user->user_id && !$user->isAdmin()) {
            return response()->json(['message' => 'This is not your order.'], 403);
        }

        $issued = $transaction->payment_verified_at ?? $transaction->completed_at;

        return response()->json([
            'message' => 'Receipt retrieved successfully',
            'data' => [
                'receipt_no' => 'FM-' . str_pad((string) $transaction->transaction_id, 6, '0', STR_PAD_LEFT),
                'issued_at' => $issued,
                // A receipt for an unverified payment would be misleading, so
                // it is marked provisional rather than withheld.
                'is_official' => $transaction->payment_status === Transaction::PAYMENT_VERIFIED,
                'status' => $transaction->status,
                'status_label' => str_replace('_', ' ', $transaction->status),

                'store_name' => config('services.gcash.account_name', 'Ofelia Store'),

                'buyer' => [
                    'name' => trim(
                        ($transaction->buyer?->studentInfo?->first_name ?? '') . ' ' .
                        ($transaction->buyer?->studentInfo?->last_name ?? '')
                    ) ?: $transaction->buyer?->email,
                    'email' => $transaction->buyer?->email,
                ],

                'item' => [
                    'item_id' => $transaction->item_id,
                    'title' => $transaction->item?->title,
                    'photo' => $transaction->item?->photos->first()?->photo_url,
                ],

                'lines' => [
                    ['label' => 'Item price', 'value' => $transaction->subtotalMoney()->toDecimalString()],
                    ['label' => 'Points used', 'value' => (string) $transaction->points_used],
                    ['label' => 'Points discount', 'value' => '-' . $transaction->discountMoney()->toDecimalString()],
                    ['label' => 'Amount paid', 'value' => $transaction->amountDueMoney()->toDecimalString()],
                ],

                'subtotal' => $transaction->subtotalMoney()->toDecimalString(),
                'points_used' => $transaction->points_used,
                'points_discount_amount' => $transaction->discountMoney()->toDecimalString(),
                'amount_paid' => $transaction->amountDueMoney()->toDecimalString(),
                'reward_points_earned' => $transaction->status === Transaction::STATUS_COMPLETED
                    ? $transaction->reward_points_earned
                    : 0,

                'payment_method' => $transaction->payment_method,
                'payment_method_label' => match ($transaction->payment_method) {
                    Transaction::METHOD_GCASH => 'GCash',
                    Transaction::METHOD_POINTS_FULL => 'Paid fully with points',
                    default => 'Cash at store',
                },
                'payment_reference' => $transaction->payment_reference,
                'payment_status' => $transaction->payment_status,
                'payment_verified_at' => $transaction->payment_verified_at,
                'completed_at' => $transaction->completed_at,
            ],
        ], 200);
    }

    /**
     * Legacy buyer checkout endpoint.
     * POST /api/transactions
     *
     * Superseded by POST /api/checkout, which carries the points/cash
     * breakdown. Kept so older app builds continue to work: it forwards to the
     * same checkout service rather than implementing a second purchase path.
     */
    public function createTransaction(Request $request)
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,item_id'],
            'payment_method' => ['nullable', 'in:cash,gcash,points,trade'],
            'points_used' => ['nullable', 'integer', 'min:0'],
        ]);

        $item = Item::where('item_id', $validated['item_id'])->first();

        if (!$item) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        // 'points' and 'trade' no longer exist as ways to pay: points are a
        // discount now, so an old points purchase becomes a cash checkout that
        // redeems as many points as the buyer asked for.
        $method = match ($validated['payment_method'] ?? 'cash') {
            'gcash' => Transaction::METHOD_GCASH,
            default => Transaction::METHOD_CASH,
        };

        try {
            $transaction = $this->checkout->checkout(
                $item,
                $request->user(),
                (int) ($validated['points_used'] ?? 0),
                $method,
            );

            return response()->json([
                'message' => 'Transaction created successfully',
                'data' => TransactionPresenter::forBuyer($transaction->load('item.photos')),
            ], 201);

        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            Log::error('Error creating transaction', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to create transaction', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Admin transaction management ─────────────────────────────────────

    /**
     * All buyer orders.
     * GET /api/admin/transactions?status=payment_proof_submitted
     */
    public function getAllTransactions(Request $request)
    {
        try {
            $query = Transaction::with(['item.photos', 'buyer.studentInfo', 'seller'])->buyerOrders();

            if ($request->filled('status')) {
                $query->where('status', $request->query('status'));
            }

            if ($request->filled('payment_status')) {
                $query->where('payment_status', $request->query('payment_status'));
            }

            if ($request->boolean('awaiting_verification')) {
                $query->where('payment_status', Transaction::PAYMENT_PROOF_SUBMITTED);
            }

            // Lets the chat screen show the order the conversation is about.
            if ($request->filled('item_id')) {
                $query->where('item_id', $request->query('item_id'));
            }

            if ($request->filled('buyer_id')) {
                $query->where('buyer_id', $request->query('buyer_id'));
            }

            if ($request->boolean('open_only')) {
                $query->open();
            }

            $transactions = $query->orderBy('transaction_date', 'desc')->get()
                ->map(fn (Transaction $t) => TransactionPresenter::forAdmin($t));

            return response()->json([
                'message' => 'Transactions retrieved successfully',
                'data' => $transactions,
                'count' => $transactions->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting transactions', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to retrieve transactions'], 500);
        }
    }

    /** GET /api/admin/transactions/{transaction_id} */
    public function getTransaction(Request $request, $transactionId)
    {
        $transaction = Transaction::with(['item.photos', 'buyer.studentInfo', 'seller'])
            ->where('transaction_id', $transactionId)
            ->first();

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        return response()->json([
            'message' => 'Transaction retrieved successfully',
            'data' => TransactionPresenter::forAdmin($transaction),
        ], 200);
    }

    /**
     * Confirm money that has arrived - a GCash transfer whose proof checks out.
     * POST /api/admin/transactions/{transaction_id}/verify-payment
     */
    public function verifyPayment(Request $request, $transactionId)
    {
        return $this->withTransaction($transactionId, function (Transaction $transaction) use ($request) {
            $transaction = $this->checkout->verifyPayment($transaction, $request->user());

            return response()->json([
                'message' => 'Payment verified',
                'data' => TransactionPresenter::forAdmin($transaction->load(['item.photos', 'buyer.studentInfo', 'seller'])),
            ], 200);
        });
    }

    /**
     * Approve a pay-at-the-store order without marking it paid.
     * POST /api/admin/transactions/{transaction_id}/approve-order
     *
     * The buyer picked cash, so nothing has been handed over yet. This accepts
     * that choice, holds the item and releases the pickup code; the money is
     * settled when the order is completed at the counter.
     */
    public function approveOrder(Request $request, $transactionId)
    {
        return $this->withTransaction($transactionId, function (Transaction $transaction) use ($request) {
            $transaction = $this->checkout->approveOrder($transaction, $request->user());

            return response()->json([
                'message' => 'Order approved. The buyer pays at the counter on pickup.',
                'data' => TransactionPresenter::forAdmin($transaction->load(['item.photos', 'buyer.studentInfo', 'seller'])),
            ], 200);
        });
    }

    /**
     * Reject a payment proof. The order ends and redeemed points go back.
     * POST /api/admin/transactions/{transaction_id}/reject-payment
     */
    public function rejectPayment(Request $request, $transactionId)
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->withTransaction($transactionId, function (Transaction $transaction) use ($request) {
            $transaction = $this->checkout->rejectPayment(
                $transaction,
                $request->user(),
                $request->input('reason'),
            );

            return response()->json([
                'message' => 'Payment proof rejected. The buyer\'s points have been restored.',
                'data' => TransactionPresenter::forAdmin($transaction->load(['item.photos', 'buyer.studentInfo', 'seller'])),
            ], 200);
        });
    }

    /** POST /api/admin/transactions/{transaction_id}/ready-for-pickup */
    public function markReadyForPickup(Request $request, $transactionId)
    {
        return $this->withTransaction($transactionId, function (Transaction $transaction) {
            $transaction = $this->checkout->markReadyForPickup($transaction);

            return response()->json([
                'message' => 'Marked ready for pickup',
                'data' => TransactionPresenter::forAdmin($transaction->load(['item.photos', 'buyer.studentInfo', 'seller'])),
            ], 200);
        });
    }

    /**
     * Complete the order: paid, and physically handed to the buyer.
     * POST /api/admin/transactions/{transaction_id}/complete
     *
     * The only place buyer reward points are credited. Safe to repeat - a
     * second call returns the already-completed order without crediting again.
     */
    public function complete(Request $request, $transactionId)
    {
        return $this->withTransaction($transactionId, function (Transaction $transaction) use ($request) {
            $alreadyCompleted = $transaction->status === Transaction::STATUS_COMPLETED;

            // The walk-in flow photographs the buyer receiving the item. The
            // photo is optional at the API - the conversation's Complete
            // button has no camera - but when one is sent it must be a real
            // image and the order must actually be completable, so a refused
            // completion does not leave a stray "proof" on an open order.
            if ($request->hasFile('handover_photo')) {
                if ($transaction->payment_status !== Transaction::PAYMENT_VERIFIED && !$alreadyCompleted) {
                    return response()->json([
                        'message' => 'Payment must be verified before completing the order.',
                    ], 409);
                }

                $file = $request->file('handover_photo');

                if ($error = $this->uploader->validateImages([$file])) {
                    return response()->json($error, 422);
                }

                $url = $this->uploader->upload($file, 'handover_photos');

                if ($url === null) {
                    return response()->json(['message' => 'Failed to upload the handover photo.'], 500);
                }

                $transaction->update(['handover_photo' => $url]);
            }

            $transaction = $this->checkout->complete($transaction, $request->user());
            $buyer = User::where('user_id', $transaction->buyer_id)->first();

            return response()->json([
                'message' => $alreadyCompleted
                    ? 'This transaction was already completed.'
                    : 'Transaction completed and reward points credited.',
                'data' => TransactionPresenter::forAdmin($transaction->load(['item.photos', 'buyer.studentInfo', 'seller'])),
                'buyer_wallet_points' => $buyer?->wallet_points,
                'reward_points_credited' => $alreadyCompleted ? 0 : $transaction->reward_points_earned,
            ], 200);
        });
    }

    /**
     * Resolve a scanned order QR to the order itself.
     * GET /api/admin/transactions/scan?code=FMQR1.12.abcdef
     *
     * The signature inside the code is checked before anything is looked up,
     * so a hand-typed or tampered code answers 404 rather than leaking whether
     * an order number exists.
     */
    public function scan(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'max:64']]);

        $transactionId = OrderQr::transactionIdFrom($request->query('code'));

        if ($transactionId === null) {
            return response()->json(['message' => 'This is not a Fati Market order code.'], 404);
        }

        return $this->withTransaction($transactionId, function (Transaction $transaction) {
            return response()->json([
                'message' => 'Order found',
                'data' => TransactionPresenter::forAdmin(
                    $transaction->load(['item.photos', 'buyer.studentInfo', 'seller']),
                ),
            ], 200);
        });
    }

    /**
     * Cancel an order with a reason, restoring any points spent.
     * POST /api/admin/transactions/{transaction_id}/cancel
     */
    public function cancel(Request $request, $transactionId)
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->withTransaction($transactionId, function (Transaction $transaction) use ($request) {
            $transaction = $this->checkout->cancel(
                $transaction,
                $request->user(),
                $request->input('reason'),
            );

            return response()->json([
                'message' => 'Transaction cancelled and points restored.',
                'data' => TransactionPresenter::forAdmin($transaction->load(['item.photos', 'buyer.studentInfo', 'seller'])),
            ], 200);
        });
    }

    /**
     * Legacy admin status update.
     * PUT /api/admin/transactions/{transaction_id}
     *
     * Older admin builds post {status: completed|cancelled}. Forwarded to the
     * proper actions so the points and item side effects still happen.
     */
    public function updateTransactionStatus(Request $request, $transactionId)
    {
        $validated = $request->validate([
            'status' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->withTransaction($transactionId, function (Transaction $transaction) use ($request, $validated) {
            $transaction = match ($validated['status']) {
                Transaction::STATUS_COMPLETED => $this->checkout->complete($transaction, $request->user()),
                Transaction::STATUS_CANCELLED, Transaction::STATUS_REJECTED => $this->checkout->cancel(
                    $transaction,
                    $request->user(),
                    $validated['reason'] ?? 'Cancelled by admin',
                ),
                Transaction::STATUS_READY_FOR_PICKUP => $this->checkout->markReadyForPickup($transaction),
                Transaction::STATUS_PAYMENT_VERIFIED, Transaction::STATUS_RESERVED => $this->checkout->verifyPayment(
                    $transaction,
                    $request->user(),
                ),
                default => throw new RuntimeException(
                    'Unsupported status. Allowed: ' . implode(', ', Transaction::ALL_STATUSES)
                ),
            };

            return response()->json([
                'message' => 'Transaction status updated successfully',
                'data' => TransactionPresenter::forAdmin($transaction->load(['item.photos', 'buyer.studentInfo', 'seller'])),
            ], 200);
        });
    }

    /**
     * Release checkouts whose reservation window has lapsed.
     * POST /api/admin/transactions/expire-abandoned
     *
     * Also runs automatically on a schedule; exposed so Admin can trigger it.
     */
    public function expireAbandoned(Request $request)
    {
        $released = $this->checkout->expireAbandonedCheckouts();

        return response()->json([
            'message' => "Released {$released} abandoned checkout(s).",
            'data' => ['released' => $released],
        ], 200);
    }

    // ── Admin-assisted in-store sale ─────────────────────────────────────

    /**
     * Hold an item for a buyer from the chat screen.
     * POST /api/admin/mark-as-reserved
     */
    public function markAsReserved(Request $request)
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,item_id'],
            'buyer_id' => ['required', 'integer', 'exists:users,user_id'],
        ]);

        $admin = $request->user();
        $buyer = User::where('user_id', $validated['buyer_id'])->first();
        $item = Item::where('item_id', $validated['item_id'])->first();

        if (!$item) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        $openOrder = Transaction::query()->buyerOrders()->open()
            ->where('item_id', $item->item_id)
            ->first();

        if ($openOrder) {
            $message = $openOrder->buyer_id === $buyer->user_id
                ? 'This item is already reserved by this user.'
                : 'Reservation failed: This item is already reserved by another user.';

            $this->postChatNotice($item, $admin, $buyer, $message);

            return response()->json(['message' => $message], 409);
        }

        if (!$item->isPurchasable()) {
            $message = 'Reservation failed: This item is no longer available.';
            $this->postChatNotice($item, $admin, $buyer, $message);

            return response()->json(['message' => 'Item is not available for reservation'], 409);
        }

        try {
            // Goes through the same checkout path a buyer would use, so the
            // hold, the reservation row and the expiry all behave identically.
            $transaction = $this->checkout->checkout($item, $buyer, 0, Transaction::METHOD_CASH);

            $this->postChatNotice(
                $item,
                $admin,
                $buyer,
                'Congratulations! You have successfully reserved this item.'
            );

            $this->notifyOtherEnquirers($item, $admin, $buyer, 'This item is already reserved by another user.');

            return response()->json([
                'message' => 'Item reserved successfully',
                'data' => TransactionPresenter::forAdmin($transaction->load(['item.photos', 'buyer.studentInfo', 'seller'])),
            ], 200);

        } catch (RuntimeException $e) {
            $this->postChatNotice($item, $admin, $buyer, 'Reservation failed: ' . $e->getMessage());

            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            Log::error('Error marking as reserved', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to mark as reserved', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Complete an in-store sale from the chat screen.
     * POST /api/admin/mark-as-sold
     *
     * Reuses the buyer checkout pipeline end to end - open the order, settle
     * the payment, complete it - so the reward points, the ledger entries and
     * the item status all follow exactly the same rules as an app checkout.
     */
    public function markAsSold(Request $request)
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,item_id'],
            'buyer_id' => ['required', 'integer', 'exists:users,user_id'],
            'payment_method' => ['nullable', 'in:cash,gcash,points,trade'],
            'points_used' => ['nullable', 'integer', 'min:0'],
        ]);

        $admin = $request->user();
        $buyer = User::where('user_id', $validated['buyer_id'])->first();
        $item = Item::where('item_id', $validated['item_id'])->first();

        if (!$item) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        $method = ($validated['payment_method'] ?? 'cash') === 'gcash'
            ? Transaction::METHOD_GCASH
            : Transaction::METHOD_CASH;

        try {
            // Continue an existing hold if this buyer already has one.
            $transaction = Transaction::query()->buyerOrders()->open()
                ->where('item_id', $item->item_id)
                ->where('buyer_id', $buyer->user_id)
                ->first();

            if ($transaction === null) {
                $blockingOrder = Transaction::query()->buyerOrders()->open()
                    ->where('item_id', $item->item_id)
                    ->exists();

                if ($blockingOrder) {
                    $message = 'Purchase failed: This item is reserved by another user.';
                    $this->postChatNotice($item, $admin, $buyer, $message);

                    return response()->json(['message' => $message], 403);
                }

                $transaction = $this->checkout->checkout(
                    $item,
                    $buyer,
                    (int) ($validated['points_used'] ?? 0),
                    $method,
                );
            }

            $transaction = $this->checkout->verifyPayment($transaction, $admin);
            $transaction = $this->checkout->complete($transaction, $admin);

            $amountDue = $transaction->amountDueMoney();
            $successMessage = $amountDue->isZero()
                ? 'Congratulations! Your points covered this item in full.'
                : 'Congratulations! You have successfully bought this item for ₱'
                    . $amountDue->toFormattedString() . '.';

            $this->postChatNotice($item, $admin, $buyer, $successMessage);
            $this->notifyOtherEnquirers($item, $admin, $buyer, 'This item is already sold to another user.');

            return response()->json([
                'message' => 'Item marked as sold successfully',
                'data' => TransactionPresenter::forAdmin($transaction->load(['item.photos', 'buyer.studentInfo', 'seller'])),
                'buyer_wallet_points' => $buyer->fresh()->wallet_points,
            ], 200);

        } catch (RuntimeException $e) {
            $this->postChatNotice($item, $admin, $buyer, 'Transaction failed: ' . $e->getMessage());

            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            Log::error('Error marking as sold', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to mark as sold', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Points ledger ────────────────────────────────────────────────────

    /**
     * The signed-in user's points ledger.
     * GET /api/points/history
     */
    public function getPointHistory(Request $request)
    {
        try {
            $user = $request->user();

            $entries = Point::with(['relatedItem' => fn ($q) => $q->select('item_id', 'title')])
                ->forUser($user->user_id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(fn (Point $entry) => [
                    'point_id' => $entry->point_id,
                    'transaction_id' => $entry->transaction_id,
                    'item_id' => $entry->item_id ?? $entry->related_item_id,
                    'item_title' => $entry->relatedItem?->title,
                    'points_change' => $entry->points_change,
                    'balance_after' => $entry->balance_after,
                    'type' => $entry->type,
                    'reason' => $entry->reason,
                    'peso_value' => $entry->pesoValue()->toDecimalString(),
                    'created_at' => $entry->created_at,
                ]);

            return response()->json([
                'message' => 'Point history retrieved successfully',
                'data' => $entries,
                'current_balance' => $user->wallet_points,
                'points_redemption_value' => LoyaltyRules::PESOS_PER_REDEEMED_POINT,
                'balance_peso_value' => LoyaltyRules::discountFor($user->availablePoints())->toDecimalString(),
                'count' => $entries->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting point history', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to retrieve point history'], 500);
        }
    }

    /**
     * Reward points credited to buyers.
     * GET /api/points/given
     */
    public function getPointsGiven(Request $request)
    {
        $entries = Point::with([
            'user' => fn ($q) => $q->select('user_id', 'email'),
            'relatedItem' => fn ($q) => $q->select('item_id', 'title'),
        ])
            ->where('points_change', '>', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'message' => 'Points given retrieved successfully',
            'data' => $entries,
            'total_points_given' => $entries->sum('points_change'),
            'count' => $entries->count(),
        ], 200);
    }

    /**
     * Points spent by buyers.
     * GET /api/points/received
     */
    public function getPointsReceived(Request $request)
    {
        $entries = Point::with([
            'user' => fn ($q) => $q->select('user_id', 'email'),
            'relatedItem' => fn ($q) => $q->select('item_id', 'title'),
        ])
            ->where('points_change', '<', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'message' => 'Points received retrieved successfully',
            'data' => $entries,
            'total_points_received' => abs($entries->sum('points_change')),
            'count' => $entries->count(),
        ], 200);
    }

    /**
     * Grant or deduct points by hand.
     * POST /api/admin/send-points
     *
     * This is a bonus/correction tool, nothing more. It used to be the
     * "Send Points & Finalize" seller payout, moving wallet points from the
     * admin to a student and writing a fake buyer transaction. Sellers are now
     * paid cash through /api/admin/items/{item}/seller-payout, and buyer
     * rewards are credited only when an order is completed.
     */
    public function sendPoints(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,user_id'],
            'points' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
            'related_item_id' => ['nullable', 'integer', 'exists:items,item_id'],
            // Lets the client make a retry safe.
            'idempotency_key' => ['nullable', 'string', 'max:191'],
        ]);

        $recipient = User::where('user_id', $validated['user_id'])->first();
        $admin = $request->user();

        if ($validated['points'] === 0) {
            return response()->json(['message' => 'Point adjustment cannot be zero.'], 422);
        }

        $key = $validated['idempotency_key']
            ?? 'admin-adjust:' . $admin->user_id . ':' . $validated['user_id'] . ':' . now()->format('YmdHis');

        try {
            $entry = DB::transaction(fn () => $this->ledger->adjust(
                $recipient,
                $validated['points'],
                $validated['reason'],
                $key,
                $validated['related_item_id'] ?? null,
            ));

            return response()->json([
                'message' => 'Points adjusted successfully',
                'data' => [
                    'recipient_id' => $recipient->user_id,
                    'recipient_email' => $recipient->email,
                    'points_change' => $validated['points'],
                    'recipient_new_balance' => $entry?->balance_after ?? $recipient->fresh()->wallet_points,
                    'reason' => $validated['reason'],
                    'idempotency_key' => $key,
                    'sent_by' => $admin->user_id,
                ],
            ], 200);

        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Error adjusting points', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to adjust points', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Payout and reward status for one item.
     * GET /api/admin/item/{item_id}/points-status
     */
    public function checkItemPointsStatus(Request $request, $itemId)
    {
        $item = Item::where('item_id', $itemId)->first();

        if (!$item) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        $orders = Transaction::buyerOrders()->where('item_id', $itemId)->get();

        return response()->json([
            'message' => 'Item points status retrieved successfully',
            'data' => [
                'item_id' => $item->item_id,
                'item_title' => $item->title,
                'item_status' => $item->status,

                // Seller side: cash, not points.
                'seller_payout_status' => $item->seller_payout_status,
                'seller_payout_amount' => $item->seller_payout_amount,
                'seller_paid_at' => $item->seller_paid_at,
                'seller_already_paid' => $item->seller_payout_status === Item::PAYOUT_PAID,

                // Retained for older admin builds that gate a button on it.
                'points_sent' => $item->seller_payout_status === Item::PAYOUT_PAID,

                // Buyer side: reward points, credited on completion.
                'reward_points' => $item->reward_points,
                'rewards_credited' => $orders
                    ->where('status', Transaction::STATUS_COMPLETED)
                    ->sum('reward_points_earned'),
                'transaction_records_count' => $orders->count(),
            ],
        ], 200);
    }

    // ── Reports ──────────────────────────────────────────────────────────

    /** GET /api/admin/transactions/cash */
    public function getCashTransactions(Request $request)
    {
        // Cash means cash handed over at the store. GCash has its own trail
        // (reference number and receipt) and its own reconciliation, so mixing
        // the two here made this list useless for counting the drawer.
        $transactions = Transaction::with(['item', 'buyer', 'seller'])
            ->buyerOrders()
            ->where('payment_method', Transaction::METHOD_CASH)
            ->orderBy('transaction_date', 'desc')
            ->get()
            ->map(fn (Transaction $t) => TransactionPresenter::forAdmin($t));

        return response()->json([
            'message' => 'Cash transactions retrieved successfully',
            'data' => $transactions,
            'count' => $transactions->count(),
        ], 200);
    }

    /**
     * GET /api/admin/transactions/trade
     *
     * Trade is no longer a payment method. Orders fully covered by points are
     * the closest equivalent, so they are what this returns.
     */
    public function getTradeTransactions(Request $request)
    {
        $transactions = Transaction::with(['item', 'buyer', 'seller'])
            ->buyerOrders()
            ->where('payment_method', Transaction::METHOD_POINTS_FULL)
            ->orderBy('transaction_date', 'desc')
            ->get()
            ->map(fn (Transaction $t) => TransactionPresenter::forAdmin($t));

        return response()->json([
            'message' => 'Full-points transactions retrieved successfully',
            'data' => $transactions,
            'count' => $transactions->count(),
            'note' => 'Trade is no longer a payment method; these are checkouts fully covered by points.',
        ], 200);
    }

    /**
     * GET /api/admin/transactions/profit-summary
     *
     * Profit is public price minus acquisition price, in pesos. The old
     * version summed `markup_points`, a column that also served as the catalog
     * price, so it reported the wrong figure once the two diverged.
     */
    public function getProfitSummary(Request $request)
    {
        $sold = Item::where('status', Item::STATUS_SOLD)->get();

        $totalProfit = $this->sumMarkup($sold);
        $monthlyProfit = $this->sumMarkup($sold->filter(
            fn (Item $item) => $item->updated_at !== null && $item->updated_at->gte(now()->subMonth())
        ));

        $completed = Transaction::buyerOrders()->where('status', Transaction::STATUS_COMPLETED)->count();

        $average = $completed > 0
            ? Money::fromCentavos(intdiv($totalProfit->centavos(), $completed))
            : Money::zero();

        return response()->json([
            'message' => 'Profit summary retrieved successfully',
            'data' => [
                'total_profit' => $totalProfit->toDecimalString(),
                'monthly_profit' => $monthlyProfit->toDecimalString(),
                'completed_transactions' => $completed,
                'average_profit_per_transaction' => $average->toDecimalString(),
                'currency' => 'PHP',
            ],
        ], 200);
    }

    /** GET /api/admin/reports/sales */
    public function getSalesReport(Request $request)
    {
        $salesByMonth = Transaction::buyerOrders()
            ->where('status', Transaction::STATUS_COMPLETED)
            ->get()
            ->groupBy(fn (Transaction $t) => optional($t->completed_at ?? $t->transaction_date)->format('Y-m'))
            ->map(fn ($group, $month) => [
                'month' => $month,
                'count' => $group->count(),
                'revenue' => $group
                    ->reduce(fn (Money $carry, Transaction $t) => $carry->plus($t->subtotalMoney()), Money::zero())
                    ->toDecimalString(),
            ])
            ->values()
            ->sortByDesc('month')
            ->take(12)
            ->values();

        $recentSales = Transaction::with(['item', 'buyer', 'seller'])
            ->buyerOrders()
            ->where('status', Transaction::STATUS_COMPLETED)
            ->orderBy('transaction_date', 'desc')
            ->limit(10)
            ->get()
            ->map(fn (Transaction $t) => TransactionPresenter::forAdmin($t));

        return response()->json([
            'message' => 'Sales report retrieved successfully',
            'data' => [
                'total_items_sold' => Item::where('status', Item::STATUS_SOLD)->count(),
                'total_items_acquired' => Item::where('status', Item::STATUS_ACQUIRED)->count(),
                'sales_by_month' => $salesByMonth,
                'recent_sales' => $recentSales,
            ],
        ], 200);
    }

    /** GET /api/admin/reports/profit */
    public function getProfitReport(Request $request)
    {
        $sold = Item::with('seller')->where('status', Item::STATUS_SOLD)->get();

        $profitByMonth = $sold
            ->groupBy(fn (Item $item) => optional($item->updated_at)->format('Y-m'))
            ->map(fn ($group, $month) => [
                'month' => $month,
                'profit' => $this->sumMarkup($group)->toDecimalString(),
            ])
            ->values()
            ->sortByDesc('month')
            ->take(12)
            ->values();

        $topItems = $sold
            ->map(fn (Item $item) => [
                'item_id' => $item->item_id,
                'title' => $item->title,
                'seller_email' => $item->seller?->email,
                'acquisition_price' => $item->acquisition_price,
                'public_price' => $item->public_price,
                'markup' => ($item->markup() ?? Money::zero())->toDecimalString(),
                'markup_centavos' => ($item->markup() ?? Money::zero())->centavos(),
            ])
            ->sortByDesc('markup_centavos')
            ->take(10)
            ->values()
            ->map(function ($row) {
                unset($row['markup_centavos']);

                return $row;
            });

        return response()->json([
            'message' => 'Profit report retrieved successfully',
            'data' => [
                'total_profit' => $this->sumMarkup($sold)->toDecimalString(),
                'profit_by_month' => $profitByMonth,
                'top_profitable_items' => $topItems,
                'currency' => 'PHP',
            ],
        ], 200);
    }

    /** GET /api/admin/reports/categories */
    public function getCategoryReport(Request $request)
    {
        $categories = \App\Models\Category::with([
            'items' => fn ($q) => $q->where('status', Item::STATUS_SOLD),
        ])->get();

        $categorySales = $categories
            ->map(function ($category) {
                $sold = $category->items;
                $profit = $this->sumMarkup($sold);
                $count = $sold->count();

                return [
                    'category_id' => $category->category_id,
                    'category_name' => $category->name,
                    'items_sold' => $count,
                    'total_profit' => $profit->toDecimalString(),
                    'average_profit_per_item' => $count > 0
                        ? Money::fromCentavos(intdiv($profit->centavos(), $count))->toDecimalString()
                        : '0.00',
                ];
            })
            ->sortByDesc('items_sold')
            ->values();

        return response()->json([
            'message' => 'Category report retrieved successfully',
            'data' => [
                'category_sales' => $categorySales,
                'most_sold_category' => $categorySales->first(),
                'currency' => 'PHP',
            ],
        ], 200);
    }

    /** GET /api/admin/reports/users */
    public function getUserReport(Request $request)
    {
        $topBuyers = User::select('user_id', 'email', 'wallet_points')
            ->where('role', User::ROLE_STUDENT)
            ->withCount(['transactionsAsBuyer' => fn ($q) => $q
                ->where('status', Transaction::STATUS_COMPLETED)
                ->where('is_seller_payout', false)])
            ->orderBy('transactions_as_buyer_count', 'desc')
            ->limit(10)
            ->get();

        $topSellers = User::select('user_id', 'email', 'wallet_points')
            ->where('role', User::ROLE_STUDENT)
            ->withCount(['soldItems' => fn ($q) => $q->whereNotNull('acquired_at')])
            ->orderBy('sold_items_count', 'desc')
            ->limit(10)
            ->get();

        $activityByMonth = User::where('role', User::ROLE_STUDENT)
            ->get()
            ->groupBy(fn (User $user) => optional($user->created_at)->format('Y-m'))
            ->map(fn ($group, $month) => ['month' => $month, 'count' => $group->count()])
            ->values()
            ->sortByDesc('month')
            ->take(12)
            ->values();

        return response()->json([
            'message' => 'User report retrieved successfully',
            'data' => [
                'active_users' => User::where('role', User::ROLE_STUDENT)->where('is_active', true)->count(),
                'total_students' => User::where('role', User::ROLE_STUDENT)->count(),
                'top_buyers' => $topBuyers,
                'top_sellers' => $topSellers,
                'user_activity_by_month' => $activityByMonth,
            ],
        ], 200);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Exact peso sum of (public price - acquisition price) over items. */
    private function sumMarkup($items): Money
    {
        return collect($items)->reduce(
            fn (Money $carry, Item $item) => $carry->plus($item->markup() ?? Money::zero()),
            Money::zero(),
        );
    }

    /** Load a buyer order and turn lifecycle violations into 409s. */
    private function withTransaction($transactionId, callable $action)
    {
        $transaction = Transaction::where('transaction_id', $transactionId)->first();

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        if ($transaction->is_seller_payout) {
            return response()->json([
                'message' => 'This record is a seller payout, not a buyer order.',
            ], 422);
        }

        try {
            return $action($transaction);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            Log::error('Transaction action failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'The action could not be completed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function postChatNotice(Item $item, User $admin, User $recipient, string $message): void
    {
        \App\Models\Message::create([
            'item_id' => $item->item_id,
            'sender_id' => $admin->user_id,
            'receiver_id' => $recipient->user_id,
            'message' => $message,
            'sent_at' => now(),
        ]);
    }

    /** Tell everyone else who asked about this item that it is spoken for. */
    private function notifyOtherEnquirers(Item $item, User $admin, User $buyer, string $message): void
    {
        $otherUserIds = \App\Models\Message::where('item_id', $item->item_id)
            ->pluck('sender_id')
            ->merge(\App\Models\Message::where('item_id', $item->item_id)->pluck('receiver_id'))
            ->unique()
            ->reject(fn ($id) => in_array($id, [$admin->user_id, $buyer->user_id, $item->seller_id], true));

        foreach ($otherUserIds as $otherUserId) {
            \App\Models\Message::create([
                'item_id' => $item->item_id,
                'sender_id' => $admin->user_id,
                'receiver_id' => $otherUserId,
                'message' => $message,
                'sent_at' => now(),
            ]);
        }
    }
}
