<?php

namespace Tests\Feature;

use App\Models\ConversationSetting;
use App\Models\Item;
use App\Models\ItemPhoto;
use App\Models\Message;
use App\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * Deleting an offer outright.
 *
 * The line this draws is possession. An offer nobody has taken in is a
 * listing and can go, along with the things that only existed for it. The
 * moment the store holds the item, the row is inventory and history, and an
 * item with an order against it can never go at all.
 */
class AdminItemDeleteTest extends MarketplaceTestCase
{
    private function offer(string $title = 'Nursing scrub suit'): Item
    {
        return Item::factory()->for($this->student(), 'seller')->create(['title' => $title]);
    }

    #[Test]
    public function an_admin_deletes_an_offer_nobody_has_taken_in(): void
    {
        $admin = $this->admin();
        $item = $this->offer();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/items/{$item->item_id}")
            ->assertOk()
            ->assertJsonPath('data.item_id', $item->item_id);

        $this->assertDatabaseMissing('items', ['item_id' => $item->item_id]);
    }

    #[Test]
    public function what_only_existed_for_the_item_goes_with_it(): void
    {
        $admin = $this->admin();
        $seller = $this->student();
        $item = Item::factory()->for($seller, 'seller')->create();

        ItemPhoto::create(['item_id' => $item->item_id, 'photo_url' => 'https://cdn.test/a.jpg']);

        Message::create([
            'item_id' => $item->item_id,
            'sender_id' => $seller->user_id,
            'receiver_id' => $admin->user_id,
            'message' => 'Available pa po?',
            'kind' => Message::KIND_TEXT,
            'sent_at' => now(),
        ]);

        ConversationSetting::forThread($admin->user_id, $item->item_id, $seller->user_id)
            ->update(['custom_name' => 'Scrubs']);

        $this->actingAs($admin)->deleteJson("/api/admin/items/{$item->item_id}")->assertOk();

        $this->assertDatabaseMissing('item_photos', ['item_id' => $item->item_id]);
        $this->assertDatabaseMissing('messages', ['item_id' => $item->item_id]);
        $this->assertDatabaseMissing('conversation_settings', ['item_id' => $item->item_id]);
    }

    #[Test]
    public function a_rejected_offer_can_also_be_cleared_away(): void
    {
        $admin = $this->admin();
        $item = $this->offer();
        $item->update(['status' => Item::STATUS_REJECTED, 'rejected_reason' => 'Too worn']);

        $this->actingAs($admin)->deleteJson("/api/admin/items/{$item->item_id}")->assertOk();

        $this->assertDatabaseMissing('items', ['item_id' => $item->item_id]);
    }

    #[Test]
    public function an_item_the_store_holds_cannot_be_deleted(): void
    {
        $admin = $this->admin();

        foreach ([
            Item::factory()->acquired('180')->for($this->student(), 'seller')->create(),
            $this->publishedItem('250', '180'),
        ] as $held) {
            $this->actingAs($admin)
                ->deleteJson("/api/admin/items/{$held->item_id}")
                ->assertStatus(409)
                ->assertJsonPath('message', "This item is already part of the store's inventory, so it cannot be deleted. Unpublish it or reject the offer instead.");

            $this->assertDatabaseHas('items', ['item_id' => $held->item_id]);
        }
    }

    #[Test]
    public function an_item_with_an_order_against_it_stays(): void
    {
        $admin = $this->admin();
        $item = $this->offer();

        Transaction::create([
            'item_id' => $item->item_id,
            'buyer_id' => $this->student()->user_id,
            'seller_id' => $item->seller_id,
            'subtotal' => '250.00',
            'amount_due' => '250.00',
            'payment_method' => 'cash',
            'status' => 'pending_payment',
            'transaction_date' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/items/{$item->item_id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('items', ['item_id' => $item->item_id]);
    }

    #[Test]
    public function a_student_cannot_delete_through_the_admin_route(): void
    {
        $seller = $this->student();
        $item = Item::factory()->for($seller, 'seller')->create();

        // Not even their own listing: this door is the store's.
        $this->actingAs($seller)->deleteJson("/api/admin/items/{$item->item_id}")->assertStatus(403);

        $this->app['auth']->forgetGuards();

        $this->deleteJson("/api/admin/items/{$item->item_id}")->assertStatus(401);

        $this->assertDatabaseHas('items', ['item_id' => $item->item_id]);
    }

    #[Test]
    public function deleting_something_that_is_not_there_says_so(): void
    {
        $this->actingAs($this->admin())
            ->deleteJson('/api/admin/items/987654')
            ->assertStatus(404);
    }
}
