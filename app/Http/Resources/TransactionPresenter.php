<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use App\Support\LoyaltyRules;
use App\Support\OrderQr;

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
            'buyer' => self::buyerProfile($transaction),
            // Who sold this. On a buyer order that is the store - it bought
            // the item from the student and owns what it sells - so the
            // student is recorded as provenance, not as the counterparty.
            // Listing them as "seller" made a buy-back read as a student
            // selling to themselves.
            'seller' => [
                'user_id' => $transaction->seller_id,
                'email' => $transaction->relationLoaded('seller') ? $transaction->seller?->email : null,
                'name' => $transaction->is_seller_payout
                    ? ($transaction->relationLoaded('seller') ? $transaction->seller?->email : null)
                    : 'Ofelia Store',
                'is_store' => !$transaction->is_seller_payout,
            ],
            // The student the item originally came from, on a buyer order.
            'consigned_by' => $transaction->is_seller_payout
                ? null
                : ($transaction->relationLoaded('seller') ? $transaction->seller?->email : null),
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

    /**
     * The buyer, as Admin needs to see them: enough to recognise the person
     * turning up at the store and to reach them if something is wrong.
     */
    private static function buyerProfile(Transaction $transaction): array
    {
        $buyer = $transaction->relationLoaded('buyer') ? $transaction->buyer : null;
        $info = $buyer?->relationLoaded('studentInfo') ? $buyer->studentInfo : $buyer?->studentInfo;

        $name = trim(($info?->first_name ?? '') . ' ' . ($info?->last_name ?? ''));

        return [
            'user_id' => $transaction->buyer_id,
            'email' => $buyer?->email,
            'name' => $name !== '' ? $name : null,
            'first_name' => $info?->first_name,
            'last_name' => $info?->last_name,
            'profile_picture' => $info?->profile_picture,
            'wallet_points' => $buyer?->wallet_points,
            'is_active' => $buyer?->is_active,
        ];
    }

    private static function common(Transaction $transaction): array
    {
        $subtotal = $transaction->subtotalMoney();
        $discount = $transaction->discountMoney();
        $amountDue = $transaction->amountDueMoney();

        return [
            'transaction_id' => $transaction->transaction_id,
            // Printed on the receipt and quoted in chat.
            'receipt_no' => 'FM-' . str_pad((string) $transaction->transaction_id, 6, '0', STR_PAD_LEFT),
            // Both audiences need these: the buyer list filters on buyer_id,
            // and it used to be missing from the buyer payload entirely.
            'buyer_id' => $transaction->buyer_id,
            'seller_id' => $transaction->seller_id,
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

            // The walk-in pickup code. The buyer renders it as a QR; Admin
            // scans it and lands on this order.
            'qr_code' => OrderQr::codeFor($transaction),
            // Proof the item was physically handed over, set on completion.
            'handover_photo' => $transaction->handover_photo,
        ];
    }

    /** @return list<string> */
    private static function availableActions(Transaction $transaction): array
    {
        if ($transaction->isTerminal()) {
            return [];
        }

        $actions = ['cancel'];

        // Verifying says the money arrived, which only a GCash proof shows.
        if ($transaction->payment_status === Transaction::PAYMENT_PROOF_SUBMITTED) {
            $actions[] = 'verify_payment';
            $actions[] = 'reject_payment';
        }

        // A cash order is approved, not paid. The money changes hands at the
        // counter, so approving accepts the method the buyer chose and holds
        // the item; the payment itself is settled by completing the order.
        if ($transaction->payment_status === Transaction::PAYMENT_UNPAID
            && $transaction->payment_method === Transaction::METHOD_CASH
            && $transaction->status === Transaction::STATUS_PENDING_PAYMENT) {
            $actions[] = 'approve_order';
        }

        // Pickup is unlocked by the approval, not by the payment - otherwise a
        // pay-at-the-store buyer could never be told their item is waiting.
        $approved = $transaction->payment_status === Transaction::PAYMENT_VERIFIED
            || in_array($transaction->status, [
                Transaction::STATUS_RESERVED,
                Transaction::STATUS_READY_FOR_PICKUP,
            ], true);

        if ($approved && $transaction->pickup_status === Transaction::PICKUP_NOT_READY) {
            $actions[] = 'mark_ready_for_pickup';
        }

        // Completing is the handover, and for cash it is also the payment, so
        // a buyer standing at the counter can be served whether or not their
        // order was approved in advance. GCash has to have landed first.
        $payableAtCounter = $transaction->payment_method === Transaction::METHOD_CASH
            && $transaction->payment_status === Transaction::PAYMENT_UNPAID;

        if ($approved || $payableAtCounter) {
            $actions[] = 'complete';
        }

        return $actions;
    }
}
