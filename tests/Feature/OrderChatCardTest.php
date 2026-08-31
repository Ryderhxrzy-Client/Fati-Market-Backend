<?php

namespace Tests\Feature;

use App\Models\ItemPhoto;
use App\Models\Message;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * The order notices that land in an item conversation.
 *
 * A buyer checking out must not see a message addressed to the store ("New
 * order #12") sitting in their own outgoing bubble. What goes into the thread
 * is written in the buyer's voice and tagged so both apps can draw a real
 * order card - item, photo, payment method, and whether it is paid yet - with
 * Admin able to settle it in place.
 */
class OrderChatCardTest extends MarketplaceTestCase
{
    /**
     * The store account, resolved the way OrderChatNotifier resolves it.
     *
     * Publishing an item already creates an admin (it records who verified the
     * turnover), so a test that calls admin() again gets a second one that no
     * order message is ever addressed to.
     */
    private function storeAdmin(): User
    {
        return User::where('role', User::ROLE_ADMIN)->orderBy('user_id')->firstOrFail();
    }

    /** @return array{0: Transaction, 1: User, 2: \App\Models\Item} */
    private function openCheckout(string $method = 'gcash', int $points = 0, int $pointsUsed = 0): array
    {
        $item = $this->publishedItem('250', '180');
        $buyer = $this->student($points);

        // The card leads with a photo that opens the item, so give it one.
        ItemPhoto::create([
            'item_id' => $item->item_id,
            'photo_url' => 'https://cdn.example.test/item.jpg',
        ]);

        $response = $this->actingAs($buyer)->postJson('/api/checkout', [
            'item_id' => $item->item_id,
            'points_used' => $pointsUsed,
            'payment_method' => $method,
        ])->assertStatus(201);

        return [
            Transaction::find($response->json('data.transaction_id')),
            $buyer,
            $item,
        ];
    }

    // ── What the buyer wrote ─────────────────────────────────────────────

    #[Test]
    public function the_checkout_message_is_written_in_the_buyers_voice(): void
    {
        [$transaction, $buyer, $item] = $this->openCheckout();

        $message = Message::where('item_id', $item->item_id)
            ->where('sender_id', $buyer->user_id)
            ->firstOrFail();

        // The old copy addressed the store from the buyer's own bubble.
        $this->assertStringNotContainsString('New order #', $message->message);

        $this->assertStringContainsString($item->title, $message->message);
        $this->assertStringContainsString('250.00', $message->message);
        $this->assertStringContainsString('GCash', $message->message);
        $this->assertStringContainsString('Not paid yet', $message->message);

        $this->assertSame(Message::KIND_ORDER_PLACED, $message->kind);
        $this->assertSame($transaction->transaction_id, $message->transaction_id);
    }

    #[Test]
    public function the_amount_in_the_message_is_what_is_actually_due(): void
    {
        [, $buyer, $item] = $this->openCheckout('gcash', 5, 2);

        $message = Message::where('item_id', $item->item_id)
            ->where('sender_id', $buyer->user_id)
            ->firstOrFail();

        // 250 - (2 points x 5) = 240
        $this->assertStringContainsString('240.00', $message->message);
        $this->assertStringContainsString('Used 2 point(s)', $message->message);
    }

    #[Test]
    public function submitting_a_gcash_receipt_posts_a_payment_card(): void
    {
        [$transaction, $buyer, $item] = $this->openCheckout();

        $this->actingAs($buyer)
            ->postJson("/api/checkout/{$transaction->transaction_id}/payment-proof", [
                'proof' => UploadedFile::fake()->image('receipt.jpg'),
                'reference_number' => 'GC123456789',
            ])->assertOk();

        $message = Message::where('item_id', $item->item_id)
            ->where('kind', Message::KIND_PAYMENT_SUBMITTED)
            ->firstOrFail();

        $this->assertSame($buyer->user_id, $message->sender_id);
        $this->assertSame($transaction->transaction_id, $message->transaction_id);
        $this->assertStringContainsString('GC123456789', $message->message);
    }

    #[Test]
    public function an_admin_decision_is_posted_back_into_the_same_thread(): void
    {
        [$transaction, $buyer, $item] = $this->openCheckout();
        $admin = $this->storeAdmin();

        $this->actingAs($buyer)
            ->postJson("/api/checkout/{$transaction->transaction_id}/payment-proof", [
                'proof' => UploadedFile::fake()->image('receipt.jpg'),
            ])->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/verify-payment")
            ->assertOk();

        $update = Message::where('item_id', $item->item_id)
            ->where('kind', Message::KIND_ORDER_UPDATE)
            ->firstOrFail();

        $this->assertSame($admin->user_id, $update->sender_id);
        $this->assertSame($buyer->user_id, $update->receiver_id);
        $this->assertSame($transaction->transaction_id, $update->transaction_id);
    }

    // ── What the clients receive ─────────────────────────────────────────

    #[Test]
    public function the_buyer_reads_the_order_card_with_their_message(): void
    {
        [$transaction, $buyer, $item] = $this->openCheckout();
        $admin = $this->storeAdmin();

        $response = $this->actingAs($buyer)
            ->getJson("/api/messages/{$item->item_id}?other_user_id={$admin->user_id}")
            ->assertOk();

        $card = collect($response->json('data'))
            ->firstWhere('kind', Message::KIND_ORDER_PLACED);

        $this->assertNotNull($card, 'the order message should be in the thread');
        $this->assertSame($transaction->transaction_id, $card['transaction_id']);

        // Everything the card draws: the item, its photo, the payment method
        // and where the payment stands.
        $this->assertSame($item->item_id, $card['order']['item']['item_id']);
        $this->assertSame($item->title, $card['order']['item']['title']);
        $this->assertNotEmpty($card['order']['item']['photos']);
        $this->assertSame(Transaction::METHOD_GCASH, $card['order']['payment_method']);
        $this->assertSame(Transaction::PAYMENT_UNPAID, $card['order']['payment_status']);
        $this->assertSame('250.00', $card['order']['amount_due']);
    }

    #[Test]
    public function an_ordinary_chat_message_carries_no_card(): void
    {
        $item = $this->publishedItem();
        $buyer = $this->student();
        $admin = $this->storeAdmin();

        $this->actingAs($buyer)
            ->postJson("/api/messages/{$item->item_id}", [
                'receiver_id' => $admin->user_id,
                'message' => 'Is this still available?',
            ])->assertStatus(201);

        $row = collect(
            $this->actingAs($buyer)
                ->getJson("/api/messages/{$item->item_id}?other_user_id={$admin->user_id}")
                ->assertOk()
                ->json('data')
        )->firstWhere('message', 'Is this still available?');

        $this->assertSame(Message::KIND_TEXT, $row['kind']);
        $this->assertNull($row['transaction_id']);
        $this->assertNull($row['order']);
    }

    #[Test]
    public function the_card_shows_paid_once_admin_verifies_it(): void
    {
        [$transaction, $buyer, $item] = $this->openCheckout();
        $admin = $this->storeAdmin();

        $this->actingAs($buyer)
            ->postJson("/api/checkout/{$transaction->transaction_id}/payment-proof", [
                'proof' => UploadedFile::fake()->image('receipt.jpg'),
            ])->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/verify-payment")
            ->assertOk();

        // The card was posted at checkout, but it reads the live order, so the
        // buyer's original message now shows the payment as settled.
        $card = collect(
            $this->actingAs($buyer)
                ->getJson("/api/messages/{$item->item_id}?other_user_id={$admin->user_id}")
                ->json('data')
        )->firstWhere('kind', Message::KIND_ORDER_PLACED);

        $this->assertSame(Transaction::PAYMENT_VERIFIED, $card['order']['payment_status']);
    }

    #[Test]
    public function admin_gets_the_proof_and_the_actions_in_the_thread(): void
    {
        [$transaction, $buyer, $item] = $this->openCheckout();
        $admin = $this->storeAdmin();

        $this->actingAs($buyer)
            ->postJson("/api/checkout/{$transaction->transaction_id}/payment-proof", [
                'proof' => UploadedFile::fake()->image('receipt.jpg'),
                'reference_number' => 'GC987654321',
            ])->assertOk();

        $card = collect(
            $this->actingAs($admin)
                ->getJson("/api/messages/{$item->item_id}?other_user_id={$buyer->user_id}")
                ->assertOk()
                ->json('data')
        )->firstWhere('kind', Message::KIND_PAYMENT_SUBMITTED);

        $this->assertNotNull($card);
        $this->assertSame('GC987654321', $card['order']['payment_reference']);
        $this->assertNotNull($card['order']['payment_proof']);

        // Approving in chat and approving on the orders screen are the same
        // decision, so the offered actions come from the same place.
        $this->assertContains('verify_payment', $card['order']['available_actions']);
        $this->assertContains('reject_payment', $card['order']['available_actions']);
    }

    #[Test]
    public function a_buyer_is_never_handed_the_admin_actions(): void
    {
        [$transaction, $buyer, $item] = $this->openCheckout();
        $admin = $this->storeAdmin();

        $this->actingAs($buyer)
            ->postJson("/api/checkout/{$transaction->transaction_id}/payment-proof", [
                'proof' => UploadedFile::fake()->image('receipt.jpg'),
            ])->assertOk();

        $card = collect(
            $this->actingAs($buyer)
                ->getJson("/api/messages/{$item->item_id}?other_user_id={$admin->user_id}")
                ->json('data')
        )->firstWhere('kind', Message::KIND_PAYMENT_SUBMITTED);

        $this->assertArrayNotHasKey('available_actions', $card['order']);
        $this->assertArrayNotHasKey('buyer', $card['order']);
    }

    // ── The thread is a history ──────────────────────────────────────────

    #[Test]
    public function the_thread_reads_as_a_history_not_a_live_dashboard(): void
    {
        [$transaction, $buyer, $item] = $this->openCheckout();
        $admin = $this->storeAdmin();

        $this->actingAs($buyer)
            ->postJson("/api/checkout/{$transaction->transaction_id}/payment-proof", [
                'proof' => UploadedFile::fake()->image('receipt.jpg'),
            ])->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/admin/transactions/{$transaction->transaction_id}/verify-payment")
            ->assertOk();

        $thread = collect(
            $this->actingAs($buyer)
                ->getJson("/api/messages/{$item->item_id}?other_user_id={$admin->user_id}")
                ->assertOk()
                ->json('data')
        );

        // Each line keeps the moment it was written, rather than all three
        // reporting the order's latest state.
        $placed = $thread->firstWhere('kind', Message::KIND_ORDER_PLACED);
        $this->assertSame(Transaction::PAYMENT_UNPAID, $placed['payment_status_at']);
        $this->assertSame(Transaction::STATUS_PENDING_PAYMENT, $placed['order_status_at']);

        $proof = $thread->firstWhere('kind', Message::KIND_PAYMENT_SUBMITTED);
        $this->assertSame(Transaction::PAYMENT_PROOF_SUBMITTED, $proof['payment_status_at']);

        $update = $thread->firstWhere('kind', Message::KIND_ORDER_UPDATE);
        $this->assertSame(Transaction::PAYMENT_VERIFIED, $update['payment_status_at']);

        // The order travelling with each card is still the live one - the
        // amounts and the item never freeze, only the state does.
        $this->assertSame(Transaction::PAYMENT_VERIFIED, $placed['order']['payment_status']);
    }
}
