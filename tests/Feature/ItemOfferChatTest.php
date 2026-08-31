<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * A new listing announces itself: posting an item opens the item's
 * conversation with a seller-voice offer, and both sides of that thread get
 * the listing itself to draw as a card - Admin with the review view, the
 * seller with their own.
 */
class ItemOfferChatTest extends MarketplaceTestCase
{
    /** @return array{0: int, 1: User, 2: User} item id, seller, admin */
    private function listItem(): array
    {
        // The notifier addresses the store account, so it must exist before
        // the listing is posted.
        $admin = User::factory()->admin()->create();
        $seller = $this->student();

        $response = $this->actingAs($seller)->postJson('/api/items', [
            'title' => 'Scientific Calculator',
            'description' => 'Casio, lightly used.',
            'category_id' => $this->category()->category_id,
            'seller_asking_price' => '350',
            'photos' => [UploadedFile::fake()->image('calc.jpg')],
        ])->assertStatus(201);

        return [$response->json('data.item_id'), $seller, $admin];
    }

    #[Test]
    public function listing_an_item_opens_the_conversation_with_the_offer(): void
    {
        [$itemId, $seller, $admin] = $this->listItem();

        $message = Message::where('item_id', $itemId)->firstOrFail();

        $this->assertSame(Message::KIND_ITEM_LISTED, $message->kind);
        $this->assertSame($seller->user_id, $message->sender_id);
        $this->assertSame($admin->user_id, $message->receiver_id);
        $this->assertNull($message->transaction_id);

        $this->assertStringContainsString('Scientific Calculator', $message->message);
        $this->assertStringContainsString('350.00', $message->message);
    }

    #[Test]
    public function admin_reads_the_offer_with_the_review_card(): void
    {
        [$itemId, $seller, $admin] = $this->listItem();

        $card = collect(
            $this->actingAs($admin)
                ->getJson("/api/messages/{$itemId}?other_user_id={$seller->user_id}")
                ->assertOk()
                ->json('data')
        )->firstWhere('kind', Message::KIND_ITEM_LISTED);

        $this->assertNotNull($card, 'the offer message should be in the thread');
        $this->assertSame($itemId, $card['item_card']['item_id']);
        $this->assertSame('350.00', $card['item_card']['seller_asking_price']);
        $this->assertSame('pending', $card['item_card']['status']);
        $this->assertNotEmpty($card['item_card']['photos']);
    }

    #[Test]
    public function the_seller_sees_their_own_view_of_the_card(): void
    {
        [$itemId, $seller, $admin] = $this->listItem();

        $card = collect(
            $this->actingAs($seller)
                ->getJson("/api/messages/{$itemId}?other_user_id={$admin->user_id}")
                ->assertOk()
                ->json('data')
        )->firstWhere('kind', Message::KIND_ITEM_LISTED);

        $this->assertNotNull($card);
        $this->assertSame('350.00', $card['item_card']['seller_asking_price']);

        // Buyer-facing review figures stay with Admin.
        $this->assertArrayNotHasKey('markup', $card['item_card']);
    }

    #[Test]
    public function plain_messages_in_the_same_thread_carry_no_card(): void
    {
        [$itemId, $seller, $admin] = $this->listItem();

        $this->actingAs($seller)->postJson("/api/messages/{$itemId}", [
            'receiver_id' => $admin->user_id,
            'message' => 'Pwede po ba bukas ko dalhin?',
        ])->assertStatus(201);

        $row = collect(
            $this->actingAs($admin)
                ->getJson("/api/messages/{$itemId}?other_user_id={$seller->user_id}")
                ->json('data')
        )->firstWhere('message', 'Pwede po ba bukas ko dalhin?');

        $this->assertSame(Message::KIND_TEXT, $row['kind']);
        $this->assertNull($row['item_card']);
    }
}
