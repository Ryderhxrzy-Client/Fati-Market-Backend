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

        // The buyer is the sender, so this reads in the buyer's own voice.
        // Clients draw the order card from `transaction_id`; this text is the
        // conversation-list preview and the fallback for older builds.
        $lines = [
            "Hi! I'd like to order \"{$item->title}\".",
            'Amount due: ₱' . $transaction->amountDueMoney()->toFormattedString()
                . ' · ' . self::paymentMethodLabel($transaction),
        ];

        if ($transaction->points_used > 0) {
            $lines[] = "Used {$transaction->points_used} point(s) "
                . '(−₱' . $transaction->discountMoney()->toFormattedString() . ')';
        }

        $lines[] = self::paymentStateSentence($transaction);

        $this->post(
            $item,
            $buyer,
            $admin,
            implode("\n", $lines),
            Message::KIND_ORDER_PLACED,
            $transaction,
        );

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
            "I've sent ₱" . $transaction->amountDueMoney()->toFormattedString()
                . ' via ' . self::paymentMethodLabel($transaction) . '.',
        ];

        if (!empty($transaction->payment_reference)) {
            $lines[] = "Reference: {$transaction->payment_reference}";
        }

        $lines[] = 'My receipt is attached - please check it, thank you!';

        $this->post(
            $item,
            $buyer,
            $admin,
            implode("\n", $lines),
            Message::KIND_PAYMENT_SUBMITTED,
            $transaction,
        );

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
        $this->post($item, $admin, $buyer, $text, Message::KIND_ORDER_UPDATE, $transaction);

        $this->fcm->sendOrderNotification(
            $transaction,
            $buyer,
            'order_update',
            'Order update',
            "{$item->title} - {$headline}",
        );
    }

    /** How the buyer is paying, worded to sit inside a sentence. */
    public static function paymentMethodLabel(Transaction $transaction): string
    {
        return match ($transaction->payment_method) {
            Transaction::METHOD_GCASH => 'GCash',
            Transaction::METHOD_POINTS_FULL => 'points',
            default => 'cash at the store',
        };
    }

    /**
     * Where the money stands, in the buyer's voice.
     *
     * This is only the text fallback. A client drawing the order card reads
     * the payment status off the order itself, so a card posted at checkout
     * starts out unpaid and turns paid the moment Admin verifies it.
     */
    public static function paymentStateSentence(Transaction $transaction): string
    {
        return match (true) {
            $transaction->payment_status === Transaction::PAYMENT_VERIFIED => 'Payment confirmed.',
            $transaction->payment_status === Transaction::PAYMENT_REJECTED => 'My payment was declined.',
            $transaction->payment_status === Transaction::PAYMENT_PROOF_SUBMITTED => 'Receipt sent - waiting for it to be checked.',
            $transaction->payment_method === Transaction::METHOD_GCASH => 'Not paid yet - I will send my GCash receipt here.',
            $transaction->payment_method === Transaction::METHOD_POINTS_FULL => 'Fully covered by my points.',
            default => 'Not paid yet - I will pay in cash at the store.',
        };
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
    private function post(
        Item $item,
        User $sender,
        User $receiver,
        string $text,
        string $kind = Message::KIND_TEXT,
        ?Transaction $transaction = null,
    ): void {
        try {
            Message::create([
                'item_id' => $item->item_id,
                'sender_id' => $sender->user_id,
                'receiver_id' => $receiver->user_id,
                'message' => $text,
                'kind' => $kind,
                'transaction_id' => $transaction?->transaction_id,
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
