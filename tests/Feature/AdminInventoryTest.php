<?php

namespace Tests\Feature;

use App\Models\Item;
use PHPUnit\Framework\Attributes\Test;

/**
 * Sections 2 and 3: admin negotiation, physical turnover, seller cash payout,
 * markup and publication.
 */
class AdminInventoryTest extends MarketplaceTestCase
{
    #[Test]
    public function pending_items_appear_on_the_admin_offers_page(): void
    {
        Item::factory()->for($this->student(), 'seller')->create(['title' => 'Pending Offer']);

        $this->actingAs($this->admin())
            ->getJson('/api/admin/items/pending')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Pending Offer')
            ->assertJsonPath('count', 1);
    }

    #[Test]
    public function admin_negotiates_and_records_a_different_acquisition_price(): void
    {
        $item = Item::factory()
            ->for($this->student(), 'seller')
            ->create(['seller_asking_price' => '200.00', 'price_points' => 200]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/items/{$item->item_id}/acquisition-price", [
                'acquisition_price' => '180',
            ])
            ->assertOk()
            ->assertJsonPath('data.acquisition_price', '180.00')
            // The asking price is preserved alongside it - the negotiation is
            // auditable, not overwritten.
            ->assertJsonPath('data.seller_asking_price', '200.00');

        $this->assertSame('180.00', $item->fresh()->acquisition_price);
    }

    #[Test]
    public function admin_receives_the_item_and_records_the_seller_cash_payout(): void
    {
        $admin = $this->admin();
        $item = Item::factory()->for($this->student(), 'seller')->create();

        $this->actingAs($admin)
            ->postJson("/api/admin/items/{$item->item_id}/verify-turnover", [
                'acquisition_price' => '180',
                'notes' => 'Received at the campus store.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Item::STATUS_ACQUIRED)
            ->assertJsonPath('data.seller_payout_status', Item::PAYOUT_UNPAID)
            ->assertJsonPath('data.seller_payout_amount', '180.00');

        $item->refresh();
        // Who verified it and when are both on record.
        $this->assertNotNull($item->acquired_at);
        $this->assertSame($admin->user_id, $item->acquired_by);

        // Paying the seller is a separate, later step.
        $this->actingAs($admin)
            ->postJson("/api/admin/items/{$item->item_id}/seller-payout")
            ->assertOk()
            ->assertJsonPath('data.seller_payout_status', Item::PAYOUT_PAID);

        $item->refresh();
        $this->assertNotNull($item->seller_paid_at);
        $this->assertSame($admin->user_id, $item->seller_paid_by);
    }

    #[Test]
    public function the_seller_payout_does_not_touch_any_wallet_points(): void
    {
        $seller = $this->student(0);
        $admin = $this->admin();
        $item = Item::factory()->acquired('180')->for($seller, 'seller')->create();

        $this->actingAs($admin)
            ->postJson("/api/admin/items/{$item->item_id}/seller-payout")
            ->assertOk();

        // The old "Send Points & Finalize" flow moved wallet points from the
        // admin to the seller. Sellers are paid cash now.
        $this->assertSame(0, $seller->fresh()->wallet_points);
        $this->assertSame(0, $admin->fresh()->wallet_points);
        $this->assertDatabaseCount('points', 0);
    }

    #[Test]
    public function a_seller_cannot_be_paid_before_the_item_is_verified(): void
    {
        $item = Item::factory()->for($this->student(), 'seller')->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/items/{$item->item_id}/seller-payout")
            ->assertStatus(422);
    }

    #[Test]
    public function publishing_at_250_previews_a_reward_of_two_points(): void
    {
        $item = Item::factory()->acquired('180')->for($this->student(), 'seller')->create();

        $this->actingAs($this->admin())
            ->getJson("/api/admin/items/{$item->item_id}/publish-preview?public_price=250")
            ->assertOk()
            ->assertJsonPath('data.public_price', '250.00')
            ->assertJsonPath('data.acquisition_price', '180.00')
            // 250 - 180 = 70 expected profit.
            ->assertJsonPath('data.markup', '70.00')
            ->assertJsonPath('data.reward_points', 2)
            ->assertJsonPath('data.can_publish', true);
    }

    #[Test]
    public function admin_publishes_at_250_and_the_item_carries_two_reward_points(): void
    {
        $item = Item::factory()
            ->acquired('180')
            ->for($this->student(), 'seller')
            ->create(['seller_asking_price' => '200.00']);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/items/{$item->item_id}/publish", ['public_price' => '250'])
            ->assertOk()
            ->assertJsonPath('data.status', Item::STATUS_PUBLIC)
            ->assertJsonPath('data.public_price', '250.00')
            ->assertJsonPath('data.reward_points', 2)
            ->assertJsonPath('data.markup', '70.00');

        $item->refresh();
        $this->assertSame(2, $item->reward_points);
        $this->assertNotNull($item->published_at);
        // The three prices stay separate.
        $this->assertSame('200.00', $item->seller_asking_price);
        $this->assertSame('180.00', $item->acquisition_price);
        $this->assertSame('250.00', $item->public_price);
    }

    #[Test]
    public function an_item_cannot_be_published_before_physical_turnover(): void
    {
        // Pending: nothing has been received or priced yet.
        $item = Item::factory()->for($this->student(), 'seller')->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/items/{$item->item_id}/publish", ['public_price' => '250'])
            ->assertStatus(422);

        $this->assertSame(Item::STATUS_PENDING, $item->fresh()->status);
    }

    #[Test]
    public function an_item_cannot_be_published_without_an_acquisition_price(): void
    {
        $item = Item::factory()->for($this->student(), 'seller')->create([
            'status' => Item::STATUS_ACQUIRED,
            'acquired_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/items/{$item->item_id}/publish", ['public_price' => '250'])
            ->assertStatus(422);
    }

    #[Test]
    public function a_public_selling_price_of_zero_is_rejected(): void
    {
        $item = Item::factory()->acquired('180')->for($this->student(), 'seller')->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/items/{$item->item_id}/publish", ['public_price' => '0'])
            ->assertStatus(422);
    }

    #[Test]
    public function only_an_admin_may_price_verify_or_publish(): void
    {
        $item = Item::factory()->acquired('180')->for($this->student(), 'seller')->create();
        $student = $this->student();

        foreach ([
            "/api/admin/items/{$item->item_id}/acquisition-price",
            "/api/admin/items/{$item->item_id}/verify-turnover",
            "/api/admin/items/{$item->item_id}/seller-payout",
            "/api/admin/items/{$item->item_id}/publish",
        ] as $endpoint) {
            $this->actingAs($student)
                ->postJson($endpoint, ['acquisition_price' => '1', 'public_price' => '1'])
                ->assertStatus(403);
        }
    }

    #[Test]
    public function an_older_admin_build_posting_status_public_still_goes_through_the_turnover_gate(): void
    {
        // Old builds PUT {status: public, markup_points: 250} to this endpoint.
        $pending = Item::factory()->for($this->student(), 'seller')->create();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/items/{$pending->item_id}", [
                'status' => Item::STATUS_PUBLIC,
                'markup_points' => 250,
            ])
            ->assertStatus(422);

        $this->assertSame(Item::STATUS_PENDING, $pending->fresh()->status);

        // The same request succeeds once the item has actually been received.
        $acquired = Item::factory()->acquired('180')->for($this->student(), 'seller')->create();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/items/{$acquired->item_id}", [
                'status' => Item::STATUS_PUBLIC,
                'markup_points' => 250,
            ])
            ->assertOk()
            ->assertJsonPath('data.public_price', '250.00')
            ->assertJsonPath('data.reward_points', 2);
    }
}
