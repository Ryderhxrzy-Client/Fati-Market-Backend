<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * A buyer on "To pay" can switch between cash and GCash - the same choice the
 * checkout offers - until the payment is under way.
 */
class ChangePaymentMethodTest extends MarketplaceTestCase
{
    private User $admin;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        // The chat notice goes to the store account, so there has to be one.
        $this->admin = $this->admin();
        $this->buyer = $this->student();
    }

    private function order(string $method): Transaction
    {
        $item = $this->publishedItem('250', '180');

        $id = $this->actingAs($this->buyer)->postJson('/api/checkout', [
            'item_id' => $item->item_id,
            'payment_method' => $method,
        ])->assertStatus(201)->json('data.transaction_id');

        return Transaction::findOrFail($id);
    }

    private function change(Transaction $order, string $method, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->buyer)
            ->postJson("/api/checkout/{$order->transaction_id}/payment-method", [
                'payment_method' => $method,
            ]);
    }

    #[Test]
    public function a_new_order_says_its_method_can_still_change(): void
    {
        $item = $this->publishedItem('250', '180');

        $this->actingAs($this->buyer)->postJson('/api/checkout', [
            'item_id' => $item->item_id,
            'payment_method' => 'cash',
        ])->assertStatus(201)->assertJsonPath('data.can_change_payment_method', true);
    }

    #[Test]
    public function an_unpaid_cash_order_switches_to_gcash(): void
    {
        $order = $this->order('cash');
        $heldUntil = $order->reserved_until->toDateTimeString();

        $this->change($order, 'gcash')
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'gcash')
            ->assertJsonPath('data.requires_payment_proof', true)
            ->assertJsonPath('data.can_change_payment_method', true);

        $fresh = $order->fresh();
        $this->assertSame(Transaction::METHOD_GCASH, $fresh->payment_method);
        $this->assertSame(Transaction::STATUS_PENDING_PAYMENT, $fresh->status);

        // Switching is not a way to hold the item for longer.
        $this->assertSame($heldUntil, $fresh->reserved_until->toDateTimeString());
    }

    #[Test]
    public function the_store_hears_about_the_change_in_the_item_thread(): void
    {
        $order = $this->order('gcash');

        $this->change($order, 'cash')->assertOk();

        $notice = Message::where('item_id', $order->item_id)
            ->where('sender_id', $this->buyer->user_id)
            ->latest('message_id')
            ->firstOrFail();

        $this->assertSame($this->admin->user_id, $notice->receiver_id);
        $this->assertStringContainsString('changed my payment method to cash at the store', $notice->message);
    }

    #[Test]
    public function choosing_the_same_method_again_changes_nothing(): void
    {
        $order = $this->order('cash');
        $messages = Message::count();

        $this->change($order, 'cash')->assertOk()->assertJsonPath('data.payment_method', 'cash');

        $this->assertSame($messages, Message::count());
    }

    #[Test]
    public function it_is_locked_once_a_gcash_receipt_is_submitted(): void
    {
        $order = $this->order('gcash');

        $this->actingAs($this->buyer)
            ->postJson("/api/checkout/{$order->transaction_id}/payment-proof", [
                'proof' => UploadedFile::fake()->image('receipt.jpg'),
            ])->assertOk();

        $this->change($order, 'cash')->assertStatus(409);
        $this->assertSame(Transaction::METHOD_GCASH, $order->fresh()->payment_method);
    }

    #[Test]
    public function it_is_locked_once_the_store_approves_a_cash_order(): void
    {
        $order = $this->order('cash');

        $this->actingAs($this->admin)
            ->postJson("/api/admin/transactions/{$order->transaction_id}/approve-order")
            ->assertOk();

        $this->change($order, 'gcash')->assertStatus(409);
        $this->assertSame(Transaction::METHOD_CASH, $order->fresh()->payment_method);
    }

    #[Test]
    public function a_cancelled_order_cannot_be_changed(): void
    {
        $order = $this->order('cash');

        $this->actingAs($this->buyer)
            ->postJson("/api/checkout/{$order->transaction_id}/cancel")
            ->assertOk();

        $this->change($order, 'gcash')
            ->assertStatus(409)
            ->assertJsonPath('message', 'This order is already closed.');
    }

    #[Test]
    public function an_order_covered_by_points_has_nothing_to_change(): void
    {
        $item = $this->publishedItem('50', '20');
        $buyer = $this->student(10);

        $id = $this->actingAs($buyer)->postJson('/api/checkout', [
            'item_id' => $item->item_id,
            'points_used' => 10,
            'payment_method' => 'cash',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.can_change_payment_method', false)
            ->json('data.transaction_id');

        $this->change(Transaction::findOrFail($id), 'gcash', $buyer)->assertStatus(409);
    }

    #[Test]
    public function only_the_buyer_can_change_it(): void
    {
        $order = $this->order('cash');

        $this->change($order, 'gcash', $this->student())->assertForbidden();
    }

    #[Test]
    public function only_cash_and_gcash_are_accepted(): void
    {
        $order = $this->order('cash');

        $this->change($order, 'points_full')->assertStatus(422);
    }
}
