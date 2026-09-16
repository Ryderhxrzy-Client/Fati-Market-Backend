<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\StudentInformation;
use App\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a sold listing says about the sale that ended it.
 *
 * The item row knows what the store paid and what it asked for, and nothing
 * about what was actually handed over: the payment method, the amount, the
 * points the buyer spent and the points they earned back all live on the
 * order. The sold list had no way to show any of it.
 */
class SoldItemSaleTest extends MarketplaceTestCase
{
    private function soldItem(array $overrides = []): array
    {
        $admin = $this->admin();
        $buyer = $this->student();

        StudentInformation::create([
            'user_id' => $buyer->user_id,
            'first_name' => 'Sheryl Cris',
            'last_name' => 'Carigma',
        ]);

        $item = $this->publishedItem('250', '180');
        $item->update(['status' => Item::STATUS_SOLD]);

        $sale = Transaction::create(array_merge([
            'item_id' => $item->item_id,
            'buyer_id' => $buyer->user_id,
            'seller_id' => $item->seller_id,
            'subtotal' => '250.00',
            'points_used' => 5,
            'points_discount_amount' => '25.00',
            'amount_due' => '225.00',
            'reward_points_earned' => 2,
            'payment_method' => 'cash',
            'payment_status' => 'verified',
            'status' => Transaction::STATUS_COMPLETED,
            'completed_at' => now(),
            'transaction_date' => now(),
        ], $overrides));

        return [$admin, $item, $sale, $buyer];
    }

    #[Test]
    public function a_sold_item_carries_the_sale_that_ended_it(): void
    {
        [$admin, $item, $sale, $buyer] = $this->soldItem();

        $row = collect($this->actingAs($admin)->getJson('/api/admin/items?status=sold')->assertOk()->json('data'))
            ->firstWhere('item_id', $item->item_id);

        $this->assertSame($sale->transaction_id, $row['sale']['transaction_id']);
        $this->assertSame('cash', $row['sale']['payment_method']);
        $this->assertSame('225.00', $row['sale']['amount_due']);
        $this->assertSame(5, $row['sale']['points_used']);
        $this->assertSame(2, $row['sale']['reward_points_earned']);
        $this->assertSame($buyer->user_id, $row['sale']['buyer_id']);
        $this->assertSame('Sheryl Cris Carigma', $row['sale']['buyer_name']);
        $this->assertStringStartsWith('FM-', $row['sale']['receipt_no']);
    }

    #[Test]
    public function an_item_that_was_never_sold_has_no_sale(): void
    {
        $admin = $this->admin();
        Item::factory()->acquired('180')->for($this->student(), 'seller')->create();

        $row = $this->actingAs($admin)->getJson('/api/admin/items?status=acquired')->assertOk()->json('data.0');

        $this->assertNull($row['sale']);
    }

    #[Test]
    public function an_order_still_in_flight_is_not_a_sale(): void
    {
        [$admin, $item] = $this->soldItem([
            'status' => Transaction::STATUS_PENDING_PAYMENT,
            'completed_at' => null,
        ]);

        $row = collect($this->actingAs($admin)->getJson('/api/admin/items?status=sold')->json('data'))
            ->firstWhere('item_id', $item->item_id);

        $this->assertNull($row['sale']);
    }

    #[Test]
    public function the_payout_to_the_seller_is_never_mistaken_for_the_sale(): void
    {
        [$admin, $item] = $this->soldItem();

        // The store paying the seller is a transaction too, and a later one.
        Transaction::create([
            'item_id' => $item->item_id,
            'buyer_id' => $item->seller_id,
            'seller_id' => $item->seller_id,
            'subtotal' => '180.00',
            'amount_due' => '180.00',
            'payment_method' => 'cash',
            'status' => Transaction::STATUS_COMPLETED,
            'completed_at' => now()->addMinute(),
            'transaction_date' => now()->addMinute(),
            'is_seller_payout' => true,
        ]);

        $row = collect($this->actingAs($admin)->getJson('/api/admin/items?status=sold')->json('data'))
            ->firstWhere('item_id', $item->item_id);

        $this->assertSame('225.00', $row['sale']['amount_due']);
    }
}
