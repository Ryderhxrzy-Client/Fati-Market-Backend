<?php

namespace App\Services;

use App\Models\Point;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * The only place in the application that changes a wallet balance.
 *
 * Two invariants hold here and nowhere else:
 *
 *  1. `users.wallet_points` is never written without an accompanying row in
 *     the `points` ledger. The balance and the ledger move together, inside
 *     one database transaction, or not at all.
 *
 *  2. Every movement carries an idempotency key backed by a UNIQUE index. A
 *     replayed request - a double-tapped button, a retried HTTP call, a
 *     duplicated webhook - finds the existing entry and returns it untouched
 *     rather than crediting or deducting a second time.
 *
 * Callers must already be inside DB::transaction(); this class refuses to run
 * otherwise, so a partial write cannot escape.
 */
class PointsLedger
{
    /**
     * Deduct points a buyer chose to redeem at checkout.
     *
     * Idempotent per transaction: redeeming twice for the same order is a
     * no-op that returns the original entry.
     */
    public function redeem(User $buyer, int $points, Transaction $transaction, string $reason = 'Points redeemed at checkout'): ?Point
    {
        if ($points <= 0) {
            return null;
        }

        return $this->record(
            user: $buyer,
            pointsChange: -$points,
            type: Point::TYPE_REDEEM,
            reason: $reason,
            idempotencyKey: $this->key(Point::TYPE_REDEEM, $transaction->transaction_id),
            transactionId: $transaction->transaction_id,
            itemId: $transaction->item_id,
        );
    }

    /**
     * Credit the reward earned on a completed purchase.
     *
     * Only ever called from the completion path, after Admin marks the
     * transaction completed.
     */
    public function reward(User $buyer, int $points, Transaction $transaction, string $reason = 'Reward for completed purchase'): ?Point
    {
        if ($points <= 0) {
            return null;
        }

        return $this->record(
            user: $buyer,
            pointsChange: $points,
            type: Point::TYPE_REWARD,
            reason: $reason,
            idempotencyKey: $this->key(Point::TYPE_REWARD, $transaction->transaction_id),
            transactionId: $transaction->transaction_id,
            itemId: $transaction->item_id,
        );
    }

    /**
     * Give back points that were redeemed on an order that was later
     * cancelled, or whose payment proof was rejected.
     */
    public function refund(User $buyer, int $points, Transaction $transaction, string $reason = 'Points restored after cancellation'): ?Point
    {
        if ($points <= 0) {
            return null;
        }

        return $this->record(
            user: $buyer,
            pointsChange: $points,
            type: Point::TYPE_REFUND,
            reason: $reason,
            idempotencyKey: $this->key(Point::TYPE_REFUND, $transaction->transaction_id),
            transactionId: $transaction->transaction_id,
            itemId: $transaction->item_id,
        );
    }

    /** A manual admin correction. The caller supplies a unique key. */
    public function adjust(User $user, int $pointsChange, string $reason, string $idempotencyKey, ?int $itemId = null): ?Point
    {
        if ($pointsChange === 0) {
            return null;
        }

        return $this->record(
            user: $user,
            pointsChange: $pointsChange,
            type: Point::TYPE_ADJUSTMENT,
            reason: $reason,
            idempotencyKey: $idempotencyKey,
            transactionId: null,
            itemId: $itemId,
        );
    }

    /** Whether a given movement has already been applied. */
    public function alreadyApplied(string $type, int $transactionId): bool
    {
        return Point::where('idempotency_key', $this->key($type, $transactionId))->exists();
    }

    public function key(string $type, int $transactionId): string
    {
        return "{$type}:txn:{$transactionId}";
    }

    /**
     * Apply one movement: lock the wallet, verify it has not already been
     * applied, write the ledger row and the new balance together.
     */
    private function record(
        User $user,
        int $pointsChange,
        string $type,
        string $reason,
        ?string $idempotencyKey,
        ?int $transactionId,
        ?int $itemId,
    ): Point {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'PointsLedger must be called inside a database transaction so the '
                . 'balance and the ledger entry commit together.'
            );
        }

        if ($idempotencyKey !== null) {
            $existing = Point::where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        // Lock the wallet row for the rest of the transaction so two concurrent
        // requests cannot both read the same starting balance.
        $locked = User::where('user_id', $user->user_id)->lockForUpdate()->first();

        if ($locked === null) {
            throw new RuntimeException("User {$user->user_id} no longer exists.");
        }

        $balanceBefore = (int) ($locked->wallet_points ?? 0);
        $balanceAfter = $balanceBefore + $pointsChange;

        if ($balanceAfter < 0) {
            throw new RuntimeException(
                "Insufficient points: balance {$balanceBefore}, requested change {$pointsChange}."
            );
        }

        try {
            $entry = Point::create([
                'user_id' => $locked->user_id,
                'transaction_id' => $transactionId,
                'item_id' => $itemId,
                // Kept in step with item_id so older API consumers still work.
                'related_item_id' => $itemId,
                'points_change' => $pointsChange,
                'balance_after' => $balanceAfter,
                'type' => $type,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request won the race. The unique index is the
            // authoritative guard; return whatever it already wrote.
            $existing = $idempotencyKey === null
                ? null
                : Point::where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }

        $locked->wallet_points = $balanceAfter;
        $locked->save();

        // Keep the caller's instance consistent with what was just committed.
        $user->wallet_points = $balanceAfter;

        return $entry;
    }
}
