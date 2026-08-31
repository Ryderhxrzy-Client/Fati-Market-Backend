<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * Section 5: checkout, the points discount and the reservation hold.
 */
class CheckoutTest extends MarketplaceTestCase
{
    #[Test]
    public function the_quote_shows_the_complete_breakdown(): void
    {
        $item = $this->publishedItem('250', '180');
        $buyer = $this->student(5);

        $this->actingAs($buyer)
            ->getJson("/api/checkout/quote?item_id={$item->item_id}&points_used=2")
            ->assertOk()
            ->assertJsonPath('data.item_price', '250.00')
            ->assertJsonPath('data.available_points', 5)
            ->assertJsonPath('data.points_used', 2)
            ->assertJsonPath('data.points_discount', '10.00')
            ->assertJsonPath('data.amount_due', '240.00')
            ->assertJsonPath('data.reward_points_on_completion', 2);
    }

    #[Test]
    public function two_points_give_a_ten_peso_discount(): void
    {
        $item = $this->publishedItem('250', '180');
        $buyer = $this->student(5);

        $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'points_used' => 2,
                'payment_method' => 'cash',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.points_discount_amount', '10.00')
            ->assertJsonPath('data.amount_due', '240.00');

        // The points leave the wallet at checkout.
        $this->assertSame(3, $buyer->fresh()->wallet_points);
    }

    #[Test]
    public function a_150_peso_item_with_2_points_leaves_140_due(): void
    {
        $item = $this->publishedItem('150', '100');
        $buyer = $this->student(2);

        $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'points_used' => 2,
                'payment_method' => 'cash',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.subtotal', '150.00')
            ->assertJsonPath('data.points_discount_amount', '10.00')
            ->assertJsonPath('data.amount_due', '140.00');
    }

    #[Test]
    public function a_50_peso_item_with_10_points_leaves_nothing_due(): void
    {
        $item = $this->publishedItem('50', '20');
        $buyer = $this->student(10);

        $response = $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'points_used' => 10,
                'payment_method' => 'cash',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.amount_due', '0.00')
            ->assertJsonPath('data.is_full_points_checkout', true)
            ->assertJsonPath('data.payment_method', Transaction::METHOD_POINTS_FULL);

        // A fully covered bill needs no cash and no GCash proof, so it is
        // already payment-verified and simply waits for pickup.
        $this->assertSame(Transaction::PAYMENT_VERIFIED, $response->json('data.payment_status'));
        $this->assertFalse($response->json('data.requires_payment_proof'));
    }

    #[Test]
    public function a_buyer_cannot_redeem_more_points_than_their_balance(): void
    {
        $item = $this->publishedItem('250', '180');
        $buyer = $this->student(3);

        $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'points_used' => 10,
                'payment_method' => 'cash',
            ])
            ->assertStatus(409);

        $this->assertSame(3, $buyer->fresh()->wallet_points);
        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function the_discount_never_makes_the_bill_negative(): void
    {
        $item = $this->publishedItem('50', '20');
        $buyer = $this->student(100);

        $response = $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'points_used' => 100,
                'payment_method' => 'cash',
            ])
            ->assertStatus(201);

        $this->assertSame('0.00', $response->json('data.amount_due'));
        // Only the 10 points the bill could absorb are spent; the rest stay.
        $this->assertSame(90, $buyer->fresh()->wallet_points);
    }

    #[Test]
    public function a_pending_checkout_reserves_the_item_against_other_buyers(): void
    {
        $item = $this->publishedItem('250', '180');

        $this->actingAs($this->student(0))
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'payment_method' => 'cash',
            ])
            ->assertStatus(201);

        $this->assertSame(Item::STATUS_RESERVED, $item->fresh()->status);

        // A second buyer is turned away.
        $this->actingAs($this->student(0))
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'payment_method' => 'cash',
            ])
            ->assertStatus(409);

        $this->assertDatabaseCount('transactions', 1);
    }

    #[Test]
    public function the_original_seller_may_buy_their_published_item_back(): void
    {
        // Once published, the item belongs to the store - the student who
        // consigned it is just another buyer of the store's stock.
        $seller = $this->student(10);
        $item = $this->publishedItem('250', '180', $seller);

        $this->actingAs($seller)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'payment_method' => 'cash',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.amount_due', '250.00');
    }

    #[Test]
    public function a_non_public_item_cannot_be_checked_out(): void
    {
        foreach ([Item::STATUS_PENDING, Item::STATUS_ACQUIRED, Item::STATUS_SOLD] as $status) {
            $item = Item::factory()
                ->acquired('100')
                ->for($this->student(), 'seller')
                ->create(['status' => $status, 'public_price' => '250.00']);

            $this->actingAs($this->student(0))
                ->postJson('/api/checkout', [
                    'item_id' => $item->item_id,
                    'payment_method' => 'cash',
                ])
                ->assertStatus(409);
        }
    }

    #[Test]
    public function the_server_ignores_prices_and_totals_sent_by_the_client(): void
    {
        $item = $this->publishedItem('250', '180');
        $buyer = $this->student(2);

        $response = $this->actingAs($buyer)
            ->postJson('/api/checkout', [
                'item_id' => $item->item_id,
                'points_used' => 2,
                'payment_method' => 'cash',
                // All of this is hostile input and must be ignored.
                'subtotal' => '1.00',
                'amount_due' => '0.00',
                'points_discount_amount' => '249.00',
                'reward_points_earned' => 9999,
                'buyer_id' => $this->admin()->user_id,
            ])
            ->assertStatus(201);

        $this->assertSame('250.00', $response->json('data.subtotal'));
        $this->assertSame('240.00', $response->json('data.amount_due'));
        $this->assertSame('10.00', $response->json('data.points_discount_amount'));
        $this->assertSame(2, $response->json('data.reward_points_to_credit'));

        // The buyer comes from the token, not the body.
        $this->assertSame($buyer->user_id, Transaction::first()->buyer_id);
    }

    #[Test]
    public function an_abandoned_checkout_releases_the_item_after_its_window_lapses(): void
    {
        $item = $this->publishedItem('250', '180');
        $buyer = $this->student(2);

        $this->actingAs($buyer)->postJson('/api/checkout', [
            'item_id' => $item->item_id,
            'points_used' => 2,
            'payment_method' => 'gcash',
        ])->assertStatus(201);

        $this->assertSame(Item::STATUS_RESERVED, $item->fresh()->status);
        $this->assertSame(0, $buyer->fresh()->wallet_points);

        // Wind the clock past the reservation window and sweep.
        $this->travel(25)->hours();
        $this->artisan('checkouts:expire')->assertSuccessful();

        $this->assertSame(Item::STATUS_PUBLIC, $item->fresh()->status);
        $this->assertSame(Transaction::STATUS_CANCELLED, Transaction::first()->status);
        // The points the buyer had staked come back.
        $this->assertSame(2, $buyer->fresh()->wallet_points);
    }

    #[Test]
    public function a_buyer_can_cancel_their_own_unpaid_checkout_and_get_their_points_back(): void
    {
        $item = $this->publishedItem('250', '180');
        $buyer = $this->student(5);

        $checkout = $this->actingAs($buyer)->postJson('/api/checkout', [
            'item_id' => $item->item_id,
            'points_used' => 5,
            'payment_method' => 'cash',
        ])->assertStatus(201);

        $transactionId = $checkout->json('data.transaction_id');
        $this->assertSame(0, $buyer->fresh()->wallet_points);

        $this->actingAs($buyer)
            ->postJson("/api/checkout/{$transactionId}/cancel")
            ->assertOk()
            ->assertJsonPath('wallet_points', 5);

        $this->assertSame(Item::STATUS_PUBLIC, $item->fresh()->status);
    }
}
