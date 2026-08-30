<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Message;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the item chat in step with the order.
 *
 * When a buyer checks out, a message is posted into that item's conversation
 * on their behalf, so the buyer and Ofelia land in a thread that already
 * contains the order details and can just talk. The same thread later carries
 * the GCash reference and the outcome.
 *
 * Conversations stay scoped per item and per buyer, which is how the existing
 * chat already works - nothing here introduces a second messaging system.
 */
class OrderChatNotifier
{
    public function __construct(private readonly FcmService $fcm)
    {
    }

    /**
     * A buyer has just placed an order.
     *
     * Posts the opening message from the buyer to Admin and pushes a
     * notification so Admin sees it without having to go looking.
     */
    public function orderPlaced(Transaction $transaction, Item $item, User $buyer): void
    {
        $admin = $this->admin();

        if ($admin === null) {
            Log::warning('No admin account to notify about a new order', [
                'transaction_id' => $transaction->transaction_id,
            ]);

            return;
        }

        $lines = [
            "🛒 New order #{$transaction->transaction_id}",
            "Item: {$item->title}",
            'Price: ₱' . $transaction->subtotalMoney()->toFormattedString(),
        ];

        if ($transaction->points_used > 0) {
            $lines[] = "Points used: {$transaction->points_used} "
                . '(−₱' . $transaction->discountMoney()->toFormattedString() . ')';
        }

        $lines[] = 'Amount due: ₱' . $transaction->amountDueMoney()->toFormattedString();
        $lines[] = match ($transaction->payment_method) {
            Transaction::METHOD_GCASH => 'Payment: GCash - I will upload my receipt here.',
            Transaction::METHOD_POINTS_FULL => 'Payment: fully covered by points.',
            default => 'Payment: cash at the store.',
        };

        $this->post($item, $buyer, $admin, implode("\n", $lines));

        $this->fcm->sendOrderNotification(
            $transaction,
            $admin,
            'order_placed',
            'New order received',
            "{$item->title} - ₱" . $transaction->amountDueMoney()->toFormattedString(),
        );
    }

    /** The buyer has uploaded a GCash receipt. */
    public function proofSubmitted(Transaction $transaction, Item $item, User $buyer): void
    {
        $admin = $this->admin();

        if ($admin === null) {
            return;
        }

        $lines = [
            "💳 Payment sent for order #{$transaction->transaction_id}",
            'Amount: ₱' . $transaction->amountDueMoney()->toFormattedString(),
        ];

        if (!empty($transaction->payment_reference)) {
            $lines[] = "GCash reference: {$transaction->payment_reference}";
        }

        $lines[] = 'Receipt uploaded - please verify.';

        $this->post($item, $buyer, $admin, implode("\n", $lines));

        $this->fcm->sendOrderNotification(
            $transaction,
            $admin,
            'payment_proof_submitted',
            'GCash proof submitted',
            "{$item->title} - ₱" . $transaction->amountDueMoney()->toFormattedString(),
        );
    }

    /**
     * Admin has decided on the order. The buyer is told in the same thread,
     * so the whole history of the purchase reads in one place.
     */
    public function outcome(Transaction $transaction, Item $item, string $headline, ?string $reason = null): void
    {
        $admin = $this->admin();
        $buyer = User::where('user_id', $transaction->buyer_id)->first();

        if ($admin === null || $buyer === null) {
            return;
        }

        $text = $headline;

        if (!empty($reason)) {
            $text .= "\nReason: {$reason}";
        }

        // From Admin to the buyer this time.
        $this->post($item, $admin, $buyer, $text);

        $this->fcm->sendOrderNotification(
            $transaction,
            $buyer,
            'order_update',
            'Order update',
            "{$item->title} - {$headline}",
        );
    }

    /** The store account. Admin ids are stable, so the first one is the owner. */
    private function admin(): ?User
    {
        return User::where('role', User::ROLE_ADMIN)->orderBy('user_id')->first();
    }

    /**
     * Write into the item's conversation.
     *
     * Failures are logged rather than thrown: a chat notice must never roll
     * back a payment that already succeeded.
     */
    private function post(Item $item, User $sender, User $receiver, string $text): void
    {
        try {
            Message::create([
                'item_id' => $item->item_id,
                'sender_id' => $sender->user_id,
                'receiver_id' => $receiver->user_id,
                'message' => $text,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to post an order message into the item chat', [
                'item_id' => $item->item_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
