<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use PHPUnit\Framework\Attributes\Test;

/**
 * The admin console pages that had no API behind them.
 *
 * Categories could only ever be read, so neither app could add or rename one,
 * and the activity page called an endpoint that did not exist - which is why it
 * always rendered "No activity logs found".
 */
class AdminConsoleTest extends MarketplaceTestCase
{
    // ── Categories ───────────────────────────────────────────────────────

    #[Test]
    public function an_admin_can_add_rename_and_delete_a_category(): void
    {
        $admin = $this->admin();

        $id = $this->actingAs($admin)
            ->postJson('/api/admin/categories', [
                'name' => 'Lab Equipment',
                'description' => 'Beakers, burners and the like',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Lab Equipment')
            ->json('data.category_id');

        $this->actingAs($admin)
            ->putJson("/api/admin/categories/{$id}", ['name' => 'Laboratory'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Laboratory');

        $this->actingAs($admin)
            ->deleteJson("/api/admin/categories/{$id}")
            ->assertOk();

        $this->assertNull(Category::find($id));
    }

    #[Test]
    public function two_categories_cannot_share_a_name(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/admin/categories', ['name' => 'Books'])
            ->assertStatus(201);

        $this->actingAs($admin)
            ->postJson('/api/admin/categories', ['name' => 'Books'])
            ->assertStatus(422);
    }

    #[Test]
    public function a_category_still_holding_items_is_not_deleted(): void
    {
        $category = $this->category();

        Item::factory()->for($this->student(), 'seller')->create([
            'category_id' => $category->category_id,
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/categories/{$category->category_id}")
            ->assertStatus(409);

        $this->assertNotNull(Category::find($category->category_id));
    }

    #[Test]
    public function a_student_cannot_change_the_categories(): void
    {
        $this->actingAs($this->student())
            ->postJson('/api/admin/categories', ['name' => 'Contraband'])
            ->assertStatus(403);
    }

    #[Test]
    public function the_category_list_says_how_many_items_each_one_holds(): void
    {
        $category = $this->category();

        Item::factory()->count(2)->for($this->student(), 'seller')->create([
            'category_id' => $category->category_id,
        ]);

        $row = collect($this->getJson('/api/categories')->json('data'))
            ->firstWhere('category_id', $category->category_id);

        $this->assertSame(2, $row['item_count']);
    }

    // ── Activity ─────────────────────────────────────────────────────────

    #[Test]
    public function the_activity_feed_reports_what_has_already_happened(): void
    {
        // No new logging was added, so this history exists purely because the
        // listing and the sale were recorded in the first place.
        $item = $this->publishedItem('250', '180');

        $feed = $this->actingAs($this->admin())
            ->getJson('/api/admin/activity')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($feed, 'The activity feed came back empty.');

        $mine = collect($feed)->where('resource_type', 'item')
            ->where('resource_id', $item->item_id);

        $this->assertNotEmpty($mine, 'The item never appeared in the feed.');

        // Every row carries what both clients render.
        foreach ($feed as $row) {
            $this->assertArrayHasKey('action', $row);
            $this->assertArrayHasKey('user', $row);
            $this->assertArrayHasKey('description', $row);
            $this->assertArrayHasKey('timestamp', $row);
            $this->assertArrayHasKey('resource_type', $row);
        }
    }

    #[Test]
    public function the_activity_feed_is_closed_to_students(): void
    {
        $this->actingAs($this->student())
            ->getJson('/api/admin/activity')
            ->assertStatus(403);
    }
}
