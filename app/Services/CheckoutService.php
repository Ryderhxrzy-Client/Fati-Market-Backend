<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Reservation;
use App\Models\Transaction;
use App\Models\User;
use App\Support\LoyaltyRules;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Buyer checkout, payment settlement and order completion.
 *
 * Every peso and every point in here is recalculated from the database. The
 * client may show the buyer a preview, but nothing it sends about prices,
 * discounts, balances or totals is trusted - only the item id, the number of
 * points the buyer wants to spend, and the payment method.
 */
class CheckoutService
{
    /**
     * How long an unpaid checkout holds an item.
     *
     * Without this an abandoned checkout would lock the item permanently.
     * Expired holds are swept by `expireAbandonedCheckouts()`, which the
     * scheduler runs every fifteen minutes.
     */
    public const RESERVATION_HOURS = 24;

    public function __construct(
        private readonly PointsLedger $ledger,
        private readonly OrderChatNotifier $notifier,
    ) {
    }

    /**
     * The server's own arithmetic for a prospective checkout.
     *
     * Also used to render the breakdown before the buyer commits, so the
     * preview and the real thing can never disagree.
     *
     * @return array{
     *     item_price: Money, available_points: int, points_used: int,
     *     points_discount: Money, amount_due: Money, reward_points: int,
     *     max_usable_points: int, is_full_points: bool
     * }
     */
    public function quote(Item $item, User $buyer, int $pointsRequested): array
    {
        $itemPrice = $item->publicPrice() ?? Money::zero();
        $available = $buyer->availablePoints();

        // Clamp rather than reject: a buyer can never spend points they do not
        // have, nor more than the bill can absorb.
        $pointsUsed = max(0, min(
            $pointsRequested,
            $available,
            LoyaltyRules::maxUsefulPoints($itemPrice),
        ));

        $discount = LoyaltyRules::discountFor($pointsUsed);
        $amountDue = $itemPrice->minus($discount)->clampAtZero();

        return [
            'item_price' => $itemPrice,
            'available_points' => $available,
            'points_used' => $pointsUsed,
            'points_discount' => $discount,
            'amount_due' => $amountDue,
            'reward_points' => LoyaltyRules::rewardPointsFor($itemPrice),
            'max_usable_points' => min($available, LoyaltyRules::maxUsefulPoints($itemPrice)),
            'is_full_points' => $amountDue->isZero(),
        ];
    }

    /**
     * Open a checkout: hold the item, take the redeemed points, and record the
     * full cash breakdown.
     *
     * @throws RuntimeException when the item cannot be bought by this buyer.
     */
    public function checkout(Item $item, User $buyer, int $pointsRequested, string $paymentMethod): Transaction
    {
        $order = DB::transaction(function () use ($item, $buyer, $pointsRequested, $paymentMethod) {
            // Re-read under a lock: between rendering the catalog and pressing
            // buy, another buyer may have taken this item.
            $locked = Item::where('item_id', $item->item_id)->lockForUpdate()->firstOrFail();

            $this->assertPurchasable($locked, $buyer);

            $freshBuyer = User::where('user_id', $buyer->user_id)->firstOrFail();
            $quote = $this->quote($locked, $freshBuyer, $pointsRequested);

            // The buyer asked to spend more than they hold. Refuse outright
            // rather than silently spending fewer points than they chose.
            if ($pointsRequested > $freshBuyer->availablePoints()) {
                throw new RuntimeException(
                    "You only have {$freshBuyer->availablePoints()} points available."
                );
            }

            $isFullPoints = $quote['is_full_points'];
            $method = $isFullPoints ? Transaction::METHOD_POINTS_FULL : $paymentMethod;

            $transaction = Transaction::create([
                'item_id' => $locked->item_id,
                'buyer_id' => $freshBuyer->user_id,
                'seller_id' => $locked->seller_id,
                'subtotal' => $quote['item_price']->toDecimalString(),
                'points_used' => $quote['points_used'],
                'points_discount_amount' => $quote['points_discount']->toDecimalString(),
                'amount_due' => $quote['amount_due']->toDecimalString(),
                // Locked in now so a later admin price change cannot alter the
                // reward this buyer was promised at checkout.
                'reward_points_earned' => $quote['reward_points'],
                'payment_method' => $method,
                'payment_status' => $isFullPoints
                    ? Transaction::PAYMENT_VERIFIED
                    : Transaction::PAYMENT_UNPAID,
                'payment_verified_at' => $isFullPoints ? now() : null,
                'pickup_status' => Transaction::PICKUP_NOT_READY,
                // Points cover the whole bill, so there is nothing to pay and
                // nothing to verify - it goes straight to reserved.
                'status' => $isFullPoints
                    ? Transaction::STATUS_RESERVED
                    : Transaction::STATUS_PENDING_PAYMENT,
                'reserved_until' => now()->addHours(self::RESERVATION_HOURS),
                'is_seller_payout' => false,
                'transaction_date' => now(),
            ]);

            if ($quote['points_used'] > 0) {
                $this->ledger->redeem(
                    $freshBuyer,
                    $quote['points_used'],
                    $transaction,
                    "Redeemed {$quote['points_used']} point(s) on \"{$locked->title}\""
                );
            }

            // Hold the item so no one else can check out against it.
            $locked->update(['status' => Item::STATUS_RESERVED]);

            Reservation::create([
                'item_id' => $locked->item_id,
                'user_id' => $freshBuyer->user_id,
                'status' => 'active',
                'expires_at' => now()->addHours(self::RESERVATION_HOURS),
            ]);

            return $transaction->fresh();
        });

        // Outside the transaction on purpose: posting a chat notice must never
        // be able to roll back a checkout that already succeeded.
        $this->notifier->orderPlaced($order, $item, $buyer);

        return $order;
    }

    /** Record a submitted GCash proof and hand the order to Admin. */
    public function attachPaymentProof(
        Transaction $transaction,
        string $proofUrl,
        ?string $reference = null,
    ): Transaction {
        if ($transaction->isTerminal()) {
            throw new RuntimeException('This order is already closed.');
        }

        if ($transaction->isFullPointsCheckout()) {
            throw new RuntimeException('This order is fully covered by points and needs no payment proof.');
        }

        $transaction->update([
            'payment_proof' => $proofUrl,
            'payment_reference' => $reference,
            'payment_proof_submitted_at' => now(),
            'payment_status' => Transaction::PAYMENT_PROOF_SUBMITTED,
            'status' => Transaction::STATUS_PAYMENT_PROOF_SUBMITTED,
        ]);

        $fresh = $transaction->fresh();
        $item = Item::where('item_id', $fresh->item_id)->first();
        $buyer = User::where('user_id', $fresh->buyer_id)->first();

        if ($item !== null && $buyer !== null) {
            $this->notifier->proofSubmitted($fresh, $item, $buyer);
        }

        return $fresh;
    }

    /**
     * Admin accepts the payment. The item stays held for the buyer.
     *
     * This is money that has actually arrived - a GCash transfer whose receipt
     * Admin has just read. Cash owed at the counter is not this: see
     * [approveOrder].
     */
    public function verifyPayment(Transaction $transaction, User $admin): Transaction
    {
        if ($transaction->isTerminal()) {
            throw new RuntimeException('This order is already closed.');
        }

        $transaction->update([
            'payment_status' => Transaction::PAYMENT_VERIFIED,
            'payment_verified_at' => now(),
            'payment_verified_by' => $admin->user_id,
            'status' => Transaction::STATUS_RESERVED,
        ]);

        $fresh = $transaction->fresh();
        $this->announce($fresh, '✅ Your payment has been verified. The item is reserved for you.');

        return $fresh;
    }

    /**
     * Admin approves a pay-at-the-store order.
     *
     * Approving a cash order accepts the payment method the buyer chose and
     * holds the item for them. It does NOT mean the money arrived - nobody has
     * handed over anything yet. Marking such an order paid put "verified" on a
     * bill nobody had settled, and the buyer's receipt then said so.
     *
     * The cash is settled where it is actually taken: at the counter, by
     * [complete]. What this approval gives the buyer is the reservation and
     * their pickup code.
     */
    public function approveOrder(Transaction $transaction, User $admin): Transaction
    {
        if ($transaction->isTerminal()) {
            throw new RuntimeException('This order is already closed.');
        }

        if ($transaction->payment_method === Transaction::METHOD_GCASH) {
            throw new RuntimeException(
                'A GCash order is approved by verifying its payment proof, not by this action.'
            );
        }

        if ($transaction->payment_status === Transaction::PAYMENT_VERIFIED) {
            throw new RuntimeException('This order is settled already.');
        }

        $transaction->update(['status' => Transaction::STATUS_RESERVED]);

        $fresh = $transaction->fresh();

        $this->announce(
            $fresh,
            '✅ Your order is approved and the item is reserved for you. Show your pickup code at '
                . 'the store and pay ₱' . $fresh->amountDueMoney()->toFormattedString()
                . ' in cash when you collect it.'
        );

        return $fresh;
    }

    /**
     * Admin rejects the payment proof.
     *
     * The order ends and any redeemed points go back to the buyer, because the
     * purchase they were spent on never happened.
     */
    public function rejectPayment(Transaction $transaction, User $admin, string $reason): Transaction
    {
        return DB::transaction(function () use ($transaction, $admin, $reason) {
            $locked = Transaction::where('transaction_id', $transaction->transaction_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isTerminal()) {
                return $locked;
            }

            $this->restoreRedeemedPoints($locked, 'Payment proof rejected');
            $this->releaseItem($locked);

            $locked->update([
                'payment_status' => Transaction::PAYMENT_REJECTED,
                'status' => Transaction::STATUS_REJECTED,
                'cancelled_at' => now(),
                'cancelled_by' => $admin->user_id,
                'cancel_reason' => $reason,
            ]);

            $fresh = $locked->fresh();
            $this->announce($fresh, '❌ Your payment proof was declined.', $reason);

            return $fresh;
        });
    }

    public function markReadyForPickup(Transaction $transaction): Transaction
    {
        if ($transaction->isTerminal()) {
            throw new RuntimeException('This order is already closed.');
        }

        if ($transaction->payment_status !== Transaction::PAYMENT_VERIFIED) {
            throw new RuntimeException('Payment must be verified before the item can be picked up.');
        }

        $transaction->update([
            'pickup_status' => Transaction::PICKUP_READY,
            'status' => Transaction::STATUS_READY_FOR_PICKUP,
        ]);

        $fresh = $transaction->fresh();
        $this->announce($fresh, '📦 Your item is ready for pickup at the store.');

        return $fresh;
    }

    /**
     * Admin completes the order: payment settled and the item physically
     * handed to the buyer. This is the only place reward points are credited.
     *
     * Safe to call repeatedly. A second call finds the order already completed
     * and returns it untouched; even if it did not, the ledger's idempotency
     * key would refuse the duplicate credit.
     */
    public function complete(Transaction $transaction, User $admin): Transaction
    {
        return DB::transaction(function () use ($transaction, $admin) {
            $locked = Transaction::where('transaction_id', $transaction->transaction_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === Transaction::STATUS_COMPLETED) {
                return $locked;
            }

            if ($locked->isTerminal()) {
                throw new RuntimeException('This order was cancelled and cannot be completed.');
            }

            if ($locked->payment_status !== Transaction::PAYMENT_VERIFIED) {
                // Cash is paid at the counter, and this is that counter: an
                // admin completing a cash order is saying the money was handed
                // over. GCash has to land before the item does, so it still has
                // to be verified from its proof first.
                if ($locked->payment_method !== Transaction::METHOD_CASH) {
                    throw new RuntimeException('Payment must be verified before completing the order.');
                }

                $locked->update([
                    'payment_status' => Transaction::PAYMENT_VERIFIED,
                    'payment_verified_at' => now(),
                    'payment_verified_by' => $admin->user_id,
                ]);
            }

            $buyer = User::where('user_id', $locked->buyer_id)->firstOrFail();

            if ($locked->reward_points_earned > 0) {
                $this->ledger->reward(
                    $buyer,
                    $locked->reward_points_earned,
                    $locked,
                    "Earned {$locked->reward_points_earned} point(s) on a completed purchase"
                );
            }

            $locked->update([
                'status' => Transaction::STATUS_COMPLETED,
                'pickup_status' => Transaction::PICKUP_PICKED_UP,
                'completed_at' => now(),
                'completed_by' => $admin->user_id,
            ]);

            Item::where('item_id', $locked->item_id)->update(['status' => Item::STATUS_SOLD]);

            Reservation::where('item_id', $locked->item_id)
                ->where('status', 'active')
                ->update(['status' => 'completed']);

            $fresh = $locked->fresh();
            $earned = $fresh->reward_points_earned;
            $this->announce(
                $fresh,
                '🎉 Order completed. Thank you!'
                    . ($earned > 0 ? " You earned {$earned} reward point(s)." : ''),
            );

            return $fresh;
        });
    }

    /**
     * Cancel an open order and put everything back: points to the buyer, item
     * to the catalog.
     */
    public function cancel(Transaction $transaction, ?User $actor, string $reason): Transaction
    {
        return DB::transaction(function () use ($transaction, $actor, $reason) {
            $locked = Transaction::where('transaction_id', $transaction->transaction_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === Transaction::STATUS_CANCELLED) {
                return $locked;
            }

            if ($locked->status === Transaction::STATUS_COMPLETED) {
                throw new RuntimeException('A completed order cannot be cancelled.');
            }

            $this->restoreRedeemedPoints($locked, $reason);
            $this->releaseItem($locked);

            $locked->update([
                'status' => Transaction::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor?->user_id,
                'cancel_reason' => $reason,
            ]);

            $fresh = $locked->fresh();
            $this->announce($fresh, '⚠️ This order was cancelled.', $reason);

            return $fresh;
        });
    }

    /**
     * Release checkouts that were never paid for.
     *
     * Without this an abandoned checkout would hold an item out of the catalog
     * forever. Run from the scheduler; also safe to call by hand.
     *
     * @return int number of checkouts released
     */
    public function expireAbandonedCheckouts(): int
    {
        $expired = Transaction::query()
            ->buyerOrders()
            ->whereIn('status', [
                Transaction::STATUS_PENDING_PAYMENT,
                Transaction::STATUS_PAYMENT_PROOF_SUBMITTED,
            ])
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<', now())
            ->get();

        $released = 0;

        foreach ($expired as $transaction) {
            try {
                $this->cancel($transaction, null, 'Reservation expired - checkout was not paid in time');
                $released++;
            } catch (\Throwable $e) {
                Log::error('Failed to expire abandoned checkout', [
                    'transaction_id' => $transaction->transaction_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $released;
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** Tell the buyer, in the item's own conversation, what just happened. */
    private function announce(Transaction $transaction, string $headline, ?string $reason = null): void
    {
        $item = Item::where('item_id', $transaction->item_id)->first();

        if ($item !== null) {
            $this->notifier->outcome($transaction, $item, $headline, $reason);
        }
    }

    /**
     * @throws RuntimeException
     */
    private function assertPurchasable(Item $item, User $buyer): void
    {
        // No self-buy rule: a published item belongs to the store, and the
        // student who consigned it is as entitled to buy it back as anyone.

        if (!$item->isPurchasable()) {
            throw new RuntimeException('This item is not available for purchase.');
        }

        if ($item->publicPrice() === null) {
            throw new RuntimeException('This item has no selling price yet.');
        }

        // Belt and braces: the item status should already have moved, but an
        // open order is the authoritative signal that it is spoken for.
        $openOrder = Transaction::query()
            ->buyerOrders()
            ->open()
            ->where('item_id', $item->item_id)
            ->exists();

        if ($openOrder) {
            throw new RuntimeException('This item is already reserved by another buyer.');
        }
    }

    /** Hand back redeemed points, once, via the ledger's idempotency key. */
    private function restoreRedeemedPoints(Transaction $transaction, string $reason): void
    {
        if ($transaction->points_used <= 0) {
            return;
        }

        $buyer = User::where('user_id', $transaction->buyer_id)->first();

        if ($buyer === null) {
            return;
        }

        $this->ledger->refund(
            $buyer,
            $transaction->points_used,
            $transaction,
            "Refund: {$reason}"
        );
    }

    /** Put the item back in the catalog if this order was holding it. */
    private function releaseItem(Transaction $transaction): void
    {
        $item = Item::where('item_id', $transaction->item_id)->lockForUpdate()->first();

        if ($item === null || $item->status === Item::STATUS_SOLD) {
            return;
        }

        if ($item->status === Item::STATUS_RESERVED) {
            $item->update(['status' => Item::STATUS_PUBLIC]);
        }

        Reservation::where('item_id', $transaction->item_id)
            ->where('user_id', $transaction->buyer_id)
            ->where('status', 'active')
            ->update(['status' => 'cancelled']);
    }
}
