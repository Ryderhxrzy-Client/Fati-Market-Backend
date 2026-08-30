<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use App\Support\LoyaltyRules;

/**
 * The order payload shared by the buyer's order list and the Admin
 * transaction screen.
 *
 * Everything the Admin screen needs is here: buyer, item, the item's cash
 * price, points used, the peso discount, the remaining cash/GCash amount, the
 * payment method and proof, the payment and pickup statuses, and the reward
 * points that will be credited on completion.
 */
class TransactionPresenter
{
    public static function forAdmin(Transaction $transaction): array
    {
        return array_merge(self::common($transaction), [
            'buyer' => [
                'user_id' => $transaction->buyer_id,
                'email' => $transaction->relationLoaded('buyer') ? $transaction->buyer?->email : null,
                'wallet_points' => $transaction->relationLoaded('buyer') ? $transaction->buyer?->wallet_points : null,
            ],
            'seller' => [
                'user_id' => $transaction->seller_id,
                'email' => $transaction->relationLoaded('seller') ? $transaction->seller?->email : null,
            ],
            'payment_proof' => $transaction->payment_proof,
            'payment_reference' => $transaction->payment_reference,
            'payment_proof_submitted_at' => $transaction->payment_proof_submitted_at,
            'payment_verified_at' => $transaction->payment_verified_at,
            'payment_verified_by' => $transaction->payment_verified_by,
            'completed_at' => $transaction->completed_at,
            'completed_by' => $transaction->completed_by,
            'cancelled_at' => $transaction->cancelled_at,
            'cancelled_by' => $transaction->cancelled_by,
            'cancel_reason' => $transaction->cancel_reason,
            'reserved_until' => $transaction->reserved_until,

            // What Admin is allowed to do next, so the UI does not have to
            // re-derive the lifecycle rules.
            'available_actions' => self::availableActions($transaction),
        ]);
    }

    public static function forBuyer(Transaction $transaction): array
    {
        return array_merge(self::common($transaction), [
            'payment_proof' => $transaction->payment_proof,
            'payment_reference' => $transaction->payment_reference,
            'reserved_until' => $transaction->reserved_until,
            'cancel_reason' => $transaction->cancel_reason,
            'completed_at' => $transaction->completed_at,
            'requires_payment_proof' => $transaction->payment_method === Transaction::METHOD_GCASH
                && $transaction->payment_proof === null
                && !$transaction->isTerminal(),
        ]);
    }

    private static function common(Transaction $transaction): array
    {
        $subtotal = $transaction->subtotalMoney();
        $discount = $transaction->discountMoney();
        $amountDue = $transaction->amountDueMoney();

        return [
            'transaction_id' => $transaction->transaction_id,
            'item_id' => $transaction->item_id,
            'item' => $transaction->relationLoaded('item') && $transaction->item ? [
                'item_id' => $transaction->item->item_id,
                'title' => $transaction->item->title,
                'status' => $transaction->item->status,
                'public_price' => $transaction->item->public_price,
                'photos' => $transaction->item->relationLoaded('photos')
                    ? $transaction->item->photos->pluck('photo_url')->toArray()
                    : [],
            ] : null,

            // The cash breakdown, exactly as it was computed at checkout.
            'subtotal' => $subtotal->toDecimalString(),
            'subtotal_formatted' => '₱' . $subtotal->toFormattedString(),
            'points_used' => $transaction->points_used,
            'points_discount_amount' => $discount->toDecimalString(),
            'points_discount_formatted' => '₱' . $discount->toFormattedString(),
            'amount_due' => $amountDue->toDecimalString(),
            'amount_due_formatted' => '₱' . $amountDue->toFormattedString(),
            'points_redemption_value' => LoyaltyRules::PESOS_PER_REDEEMED_POINT,

            'payment_method' => $transaction->payment_method,
            'payment_status' => $transaction->payment_status,
            'pickup_status' => $transaction->pickup_status,
            'status' => $transaction->status,

            // Credited only when Admin marks the order completed.
            'reward_points_to_credit' => $transaction->reward_points_earned,
            'reward_points_credited' => $transaction->status === Transaction::STATUS_COMPLETED,

            'is_full_points_checkout' => $transaction->isFullPointsCheckout(),
            'transaction_date' => $transaction->transaction_date,
            'created_at' => $transaction->created_at,
        ];
    }

    /** @return list<string> */
    private static function availableActions(Transaction $transaction): array
    {
        if ($transaction->isTerminal()) {
            return [];
        }

        $actions = ['cancel'];

        if ($transaction->payment_status === Transaction::PAYMENT_PROOF_SUBMITTED) {
            $actions[] = 'verify_payment';
            $actions[] = 'reject_payment';
        }

        if ($transaction->payment_status === Transaction::PAYMENT_UNPAID
            && $transaction->payment_method === Transaction::METHOD_CASH) {
            // Cash is handed over at the store, so Admin confirms it directly.
            $actions[] = 'verify_payment';
        }

        if ($transaction->payment_status === Transaction::PAYMENT_VERIFIED
            && $transaction->pickup_status === Transaction::PICKUP_NOT_READY) {
            $actions[] = 'mark_ready_for_pickup';
        }

        if ($transaction->payment_status === Transaction::PAYMENT_VERIFIED) {
            $actions[] = 'complete';
        }

        return $actions;
    }
}
