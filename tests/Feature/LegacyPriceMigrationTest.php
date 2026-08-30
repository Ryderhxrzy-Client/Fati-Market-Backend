<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Section 9: how point-priced rows created before the cash refactor are
 * converted.
 *
 * The conversion migration runs against rows that already exist, so these
 * tests insert legacy-shaped rows and re-run just that migration, rather than
 * asserting on data the factories produce.
 */
class LegacyPriceMigrationTest extends MarketplaceTestCase
{
    /** Insert a row exactly as the old schema would have left it. */
    private function insertLegacyItem(array $overrides = []): int
    {
        $seller = $this->student();

        return DB::table('items')->insertGetId(array_merge([
            'seller_id' => $seller->user_id,
            'title' => 'Legacy Item',
            'description' => 'Priced in points under the old model.',
            'category_id' => $this->category()->category_id,
            'status' => 'private',
            'price_points' => 200,
            'markup_points' => 250,
            'seller_asking_price' => null,
            'acquisition_price' => null,
            'public_price' => null,
            'reward_points' => 0,
            'seller_payout_status' => Item::PAYOUT_UNPAID,
            'price_source' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'item_id');
    }

    private function runConversion(): void
    {
        $migration = require database_path(
            'migrations/2026_08_31_000005_convert_legacy_point_prices_to_pesos.php'
        );

        $migration->up();
    }

    #[Test]
    public function a_legacy_point_price_becomes_the_same_number_of_pesos(): void
    {
        $itemId = $this->insertLegacyItem();

        $this->runConversion();

        $item = Item::find($itemId);

        // 200 points -> PHP 200.00, and the origin of the number is recorded.
        $this->assertSame('200.00', $item->seller_asking_price);
        $this->assertSame('legacy_points', $item->price_source);
        $this->assertTrue($item->isLegacyPriced());
    }

    #[Test]
    public function a_legacy_private_item_becomes_pending(): void
    {
        $itemId = $this->insertLegacyItem(['status' => 'private']);

        $this->runConversion();

        $this->assertSame(Item::STATUS_PENDING, Item::find($itemId)->status);
    }

    #[Test]
    public function a_legacy_catalog_item_keeps_its_buyer_facing_price_and_gains_reward_points(): void
    {
        $itemId = $this->insertLegacyItem([
            'status' => 'public',
            'price_points' => 200,
            'markup_points' => 250,
        ]);

        $this->runConversion();

        $item = Item::find($itemId);

        // markup_points was what old buyer builds displayed as the price.
        $this->assertSame('250.00', $item->public_price);
        // Admin paid the seller the asking points under the old flow.
        $this->assertSame('200.00', $item->acquisition_price);
        // Reward follows the same floor(price / 100) rule as new listings.
        $this->assertSame(2, $item->reward_points);
        // Derived markup: 250 - 200.
        $this->assertSame('50.00', $item->markup()->toDecimalString());
    }

    #[Test]
    public function a_legacy_pending_item_gets_no_public_price_or_reward(): void
    {
        $itemId = $this->insertLegacyItem(['status' => 'private', 'markup_points' => 250]);

        $this->runConversion();

        $item = Item::find($itemId);

        // It never reached the catalog, so it has no selling price to carry
        // over and Admin must set one before it can be published.
        $this->assertNull($item->public_price);
        $this->assertNull($item->acquisition_price);
        $this->assertSame(0, $item->reward_points);
        $this->assertFalse($item->canBePublished());
    }

    #[Test]
    public function a_seller_already_paid_in_points_is_marked_as_paid(): void
    {
        $itemId = $this->insertLegacyItem(['status' => 'acquired']);

        // The old "Send Points & Finalize" left a points row with reason 'sale'.
        DB::table('points')->insert([
            'user_id' => $this->student()->user_id,
            'points_change' => 200,
            'reason' => 'sale',
            'related_item_id' => $itemId,
            'type' => 'adjustment',
            'created_at' => now(),
        ]);

        $this->runConversion();

        $item = Item::find($itemId);

        $this->assertSame(Item::PAYOUT_PAID, $item->seller_payout_status);
        $this->assertSame('200.00', $item->seller_payout_amount);
    }

    #[Test]
    public function legacy_ledger_entries_are_classified_apart_from_current_rewards(): void
    {
        $user = $this->student();

        DB::table('points')->insert([
            ['user_id' => $user->user_id, 'points_change' => -50, 'reason' => 'purchase', 'type' => 'adjustment', 'created_at' => now()],
            ['user_id' => $user->user_id, 'points_change' => 50, 'reason' => 'sale', 'type' => 'adjustment', 'created_at' => now()],
        ]);

        $this->runConversion();

        // Distinct types keep points-as-currency history out of reward totals.
        $this->assertDatabaseHas('points', ['reason' => 'purchase', 'type' => 'legacy_purchase']);
        $this->assertDatabaseHas('points', ['reason' => 'sale', 'type' => 'legacy_payout']);
        $this->assertDatabaseMissing('points', ['type' => \App\Models\Point::TYPE_REWARD]);
    }

    #[Test]
    public function the_conversion_can_be_run_twice_without_changing_the_result(): void
    {
        $itemId = $this->insertLegacyItem(['status' => 'public']);

        $this->runConversion();
        $first = Item::find($itemId)->only(['seller_asking_price', 'acquisition_price', 'public_price', 'reward_points']);

        $this->runConversion();
        $second = Item::find($itemId)->only(['seller_asking_price', 'acquisition_price', 'public_price', 'reward_points']);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function a_legacy_priced_item_still_flows_through_the_new_admin_gates(): void
    {
        $itemId = $this->insertLegacyItem(['status' => 'private']);
        $this->runConversion();

        $admin = $this->admin();

        // It cannot go straight to the catalog on its converted number alone.
        $this->actingAs($admin)
            ->postJson("/api/admin/items/{$itemId}/publish", ['public_price' => '250'])
            ->assertStatus(422);

        // The normal turnover path still applies.
        $this->actingAs($admin)
            ->postJson("/api/admin/items/{$itemId}/verify-turnover", ['acquisition_price' => '180'])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/admin/items/{$itemId}/publish", ['public_price' => '250'])
            ->assertOk()
            ->assertJsonPath('data.reward_points', 2)
            // Publishing at a real peso price clears the legacy marker.
            ->assertJsonPath('data.price_source', 'cash');
    }
}
