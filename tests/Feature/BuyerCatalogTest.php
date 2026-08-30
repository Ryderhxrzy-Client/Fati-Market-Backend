<?php

namespace Tests\Feature;

use App\Models\Item;
use PHPUnit\Framework\Attributes\Test;

/**
 * Section 4: the public buyer catalog shows the official cash price and the
 * reward preview, and nothing that is not published.
 */
class BuyerCatalogTest extends MarketplaceTestCase
{
    #[Test]
    public function the_catalog_shows_the_cash_price_and_the_reward_preview(): void
    {
        $this->publishedItem('250', '180');

        $this->getJson('/api/items')
            ->assertOk()
            ->assertJsonPath('data.0.price', '250.00')
            ->assertJsonPath('data.0.public_price', '250.00')
            ->assertJsonPath('data.0.price_formatted', '₱250.00')
            ->assertJsonPath('data.0.reward_points', 2)
            ->assertJsonPath('data.0.reward_label', 'Earn 2 points after completed purchase');
    }

    #[Test]
    public function a_single_reward_point_is_described_in_the_singular(): void
    {
        $this->publishedItem('150', '100');

        $this->getJson('/api/items')
            ->assertOk()
            ->assertJsonPath('data.0.reward_points', 1)
            ->assertJsonPath('data.0.reward_label', 'Earn 1 point after completed purchase');
    }

    #[Test]
    public function only_public_items_appear_in_the_catalog(): void
    {
        $this->publishedItem('250', '180');
        Item::factory()->for($this->student(), 'seller')->create();
        Item::factory()->acquired('100')->for($this->student(), 'seller')->create();
        Item::factory()->for($this->student(), 'seller')->create(['status' => Item::STATUS_SOLD]);

        $this->getJson('/api/items')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', Item::STATUS_PUBLIC);
    }

    #[Test]
    public function markup_points_is_never_presented_as_the_price(): void
    {
        // Acquisition 180, public 250 - the markup is 70. A buyer must never
        // see 70 anywhere near the price.
        $this->publishedItem('250', '180');

        $response = $this->getJson('/api/items')->assertOk();

        $this->assertSame('250.00', $response->json('data.0.price'));
        // The legacy key is kept for old builds, but it mirrors the peso price
        // rather than the profit.
        $this->assertSame(250, $response->json('data.0.markup_points'));
    }

    #[Test]
    public function price_sorting_uses_the_public_cash_price(): void
    {
        $this->publishedItem('500', '300');
        $this->publishedItem('100', '50');
        $this->publishedItem('250', '180');

        $ascending = $this->getJson('/api/items?sort=price_asc')->assertOk();
        $this->assertSame(
            ['100.00', '250.00', '500.00'],
            array_column($ascending->json('data'), 'price')
        );

        $descending = $this->getJson('/api/items?sort=price_desc')->assertOk();
        $this->assertSame(
            ['500.00', '250.00', '100.00'],
            array_column($descending->json('data'), 'price')
        );
    }

    #[Test]
    public function the_catalog_can_be_filtered_by_price_range_search_and_category(): void
    {
        $cheap = $this->publishedItem('100', '50');
        $cheap->update(['title' => 'Blue Notebook']);
        $this->publishedItem('900', '600');

        $this->getJson('/api/items?price_max=500')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.price', '100.00');

        $this->getJson('/api/items?search=Notebook')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Blue Notebook');

        $this->getJson("/api/items?category_id={$cheap->category_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_pending_item_is_not_visible_to_other_students(): void
    {
        $item = Item::factory()->for($this->student(), 'seller')->create();

        $this->actingAs($this->student())
            ->getJson("/api/items/{$item->item_id}")
            ->assertStatus(404);
    }

    #[Test]
    public function a_seller_viewing_their_own_pending_item_sees_no_reward_figure(): void
    {
        $seller = $this->student();
        $item = Item::factory()->for($seller, 'seller')->create(['seller_asking_price' => '200.00']);

        $this->actingAs($seller)
            ->getJson("/api/items/{$item->item_id}")
            ->assertOk()
            ->assertJsonPath('data.seller_asking_price', '200.00')
            ->assertJsonMissingPath('data.reward_points')
            ->assertJsonMissingPath('data.public_price');
    }

    #[Test]
    public function an_admin_sees_every_price_on_the_item_detail(): void
    {
        $item = $this->publishedItem('250', '180');

        $this->actingAs($this->admin())
            ->getJson("/api/items/{$item->item_id}")
            ->assertOk()
            ->assertJsonPath('data.acquisition_price', '180.00')
            ->assertJsonPath('data.public_price', '250.00')
            ->assertJsonPath('data.markup', '70.00')
            ->assertJsonPath('data.reward_points', 2);
    }
}
