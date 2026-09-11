<?php

namespace Tests\Feature;

use App\Models\Item;
use PHPUnit\Framework\Attributes\Test;

/**
 * A seller's "My listings" keeps everything they ever offered - under review,
 * declined, handed over, sold - not just what is still pending.
 */
class MyListingsHistoryTest extends MarketplaceTestCase
{
    #[Test]
    public function a_seller_sees_every_listing_they_offered_newest_first(): void
    {
        $seller = $this->student();
        $mine = Item::factory()->for($seller, 'seller');

        $pending = $mine->create(['created_at' => now()->subDays(1)]);
        $acquired = $mine->acquired()->create(['created_at' => now()->subDays(2)]);
        $sold = $mine->published()->create(['status' => Item::STATUS_SOLD, 'created_at' => now()->subDays(3)]);
        $rejected = $mine->create([
            'status' => Item::STATUS_REJECTED,
            'rejected_reason' => 'Too worn for resale.',
            'created_at' => now()->subDays(4),
        ]);

        // Someone else's listing never shows up.
        Item::factory()->for($this->student(), 'seller')->create();

        $data = $this->actingAs($seller)->getJson('/api/items/mine')->assertOk()->json('data');

        $this->assertSame(
            [$pending->item_id, $acquired->item_id, $sold->item_id, $rejected->item_id],
            array_column($data, 'item_id'),
        );
        $this->assertSame(['pending', 'acquired', 'sold', 'rejected'], array_column($data, 'status'));
        $this->assertSame('Too worn for resale.', $data[3]['rejected_reason']);
    }

    #[Test]
    public function the_history_is_the_sellers_own_view(): void
    {
        $seller = $this->student();
        Item::factory()->for($seller, 'seller')->acquired('180')->create();

        $row = $this->actingAs($seller)->getJson('/api/items/mine')->assertOk()->json('data.0');

        // What the store agreed to pay is the seller's business...
        $this->assertSame('180.00', $row['acquisition_price']);

        // ...the store's markup and the buyer's reward are not.
        $this->assertArrayNotHasKey('markup', $row);
        $this->assertArrayNotHasKey('reward_points', $row);
    }

    #[Test]
    public function it_needs_a_signed_in_student(): void
    {
        $this->getJson('/api/items/mine')->assertUnauthorized();
    }

    #[Test]
    public function item_ids_still_resolve_past_the_new_route(): void
    {
        $item = $this->publishedItem();

        $this->getJson("/api/items/{$item->item_id}")
            ->assertOk()
            ->assertJsonPath('data.item_id', $item->item_id);
    }
}
