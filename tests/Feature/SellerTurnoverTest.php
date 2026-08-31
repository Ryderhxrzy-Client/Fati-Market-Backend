<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Message;
use App\Models\User;
use App\Support\ItemQr;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * The seller-side turnover: an accepted offer tells the seller and hands them
 * a QR; the QR is scanned at the counter; the turnover stores its two proof
 * photos; the scheduled meet-up reminds the seller as it approaches.
 */
class SellerTurnoverTest extends MarketplaceTestCase
{
    /** @return array{0: Item, 1: User, 2: User} item, seller, admin */
    private function listedItem(): array
    {
        $admin = User::factory()->admin()->create();
        $seller = $this->student();

        $itemId = $this->actingAs($seller)->postJson('/api/items', [
            'title' => 'Lab Gown',
            'description' => 'Size M, worn twice.',
            'category_id' => $this->category()->category_id,
            'seller_asking_price' => '300',
            'photos' => [UploadedFile::fake()->image('gown.jpg')],
        ])->assertStatus(201)->json('data.item_id');

        return [Item::findOrFail($itemId), $seller, $admin];
    }

    // ── Accepting ────────────────────────────────────────────────────────

    #[Test]
    public function a_pending_offer_has_no_qr_until_it_is_accepted(): void
    {
        [$item, $seller, $admin] = $this->listedItem();

        $mine = fn () => collect(
            $this->actingAs($seller)->getJson('/api/items?status=pending')->json('data')
        )->firstWhere('item_id', $item->item_id);

        $this->assertFalse($mine()['offer_accepted']);
        $this->assertNull($mine()['qr_code']);

        $this->actingAs($admin)
            ->postJson("/api/admin/items/{$item->item_id}/acquisition-price", [
                'acquisition_price' => '250',
            ])->assertOk();

        $row = $mine();
        $this->assertTrue($row['offer_accepted']);
        $this->assertSame(ItemQr::codeFor($item), $row['qr_code']);
    }

    #[Test]
    public function accepting_tells_the_seller_in_their_thread(): void
    {
        [$item, $seller, $admin] = $this->listedItem();

        $this->actingAs($admin)
            ->postJson("/api/admin/items/{$item->item_id}/acquisition-price", [
                'acquisition_price' => '250',
            ])->assertOk();

        $notice = Message::where('item_id', $item->item_id)
            ->where('sender_id', '!=', $seller->user_id)
            ->latest('message_id')
            ->firstOrFail();

        $this->assertSame($seller->user_id, $notice->receiver_id);
        $this->assertStringContainsString('accepted', $notice->message);
        $this->assertStringContainsString('250.00', $notice->message);
    }

    #[Test]
    public function declining_tells_the_seller_why(): void
    {
        [$item, $seller, $admin] = $this->listedItem();

        $this->actingAs($admin)
            ->postJson("/api/admin/items/{$item->item_id}/reject", [
                'reason' => 'Too worn for resale.',
            ])->assertOk();

        $notice = Message::where('item_id', $item->item_id)
            ->where('receiver_id', $seller->user_id)
            ->latest('message_id')
            ->firstOrFail();

        $this->assertStringContainsString('declined', $notice->message);
        $this->assertStringContainsString('Too worn for resale.', $notice->message);
    }

    // ── Scanning ─────────────────────────────────────────────────────────

    #[Test]
    public function the_item_code_scans_to_the_listing_and_never_to_an_order(): void
    {
        [$item, , $admin] = $this->listedItem();

        $this->actingAs($admin)
            ->postJson("/api/admin/items/{$item->item_id}/acquisition-price", [
                'acquisition_price' => '250',
            ])->assertOk();

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/items/scan?code=' . urlencode(ItemQr::codeFor($item)))
            ->assertOk();

        $this->assertSame($item->item_id, $response->json('data.item_id'));
        $this->assertSame('300.00', $response->json('data.seller_asking_price'));

        // The two code families never cross.
        $this->actingAs($admin)
            ->getJson('/api/admin/transactions/scan?code=' . urlencode(ItemQr::codeFor($item)))
            ->assertNotFound();
        $this->actingAs($admin)
            ->getJson('/api/admin/items/scan?code=not-a-code')
            ->assertNotFound();
    }

    // ── The turnover itself ──────────────────────────────────────────────

    #[Test]
    public function verifying_the_turnover_stores_both_proof_photos(): void
    {
        [$item, , $admin] = $this->listedItem();

        $this->actingAs($admin)
            ->postJson("/api/admin/items/{$item->item_id}/acquisition-price", [
                'acquisition_price' => '250',
            ])->assertOk();

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/items/{$item->item_id}/verify-turnover", [
                'turnover_photo' => UploadedFile::fake()->image('received.jpg'),
                'payout_photo' => UploadedFile::fake()->image('paid.jpg'),
            ])->assertOk();

        $this->assertSame('acquired', $response->json('data.status'));
        $this->assertNotNull($response->json('data.turnover_photo'));
        $this->assertNotNull($response->json('data.seller_payout_photo'));

        // Acquired: the turnover code has done its job.
        $this->assertNull($response->json('data.qr_code'));
    }

    // ── Reminders ────────────────────────────────────────────────────────

    #[Test]
    public function scheduling_notifies_the_seller_and_reminders_fire_once_each(): void
    {
        [$item, $seller, $admin] = $this->listedItem();

        $this->actingAs($admin)
            ->postJson("/api/admin/items/{$item->item_id}/acquisition-price", [
                'acquisition_price' => '250',
            ])->assertOk();

        $meetup = Carbon::now()->addHours(8);

        $this->actingAs($admin)
            ->postJson("/api/admin/items/{$item->item_id}/meetup", [
                'meetup_schedule' => $meetup->toDateTimeString(),
            ])->assertOk();

        $this->assertStringContainsString(
            'Meet-up set',
            Message::where('receiver_id', $seller->user_id)->latest('message_id')->firstOrFail()->message,
        );

        // Eight hours out: nothing is due yet.
        $this->artisan('meetups:remind')->assertSuccessful();
        $this->assertSame('', (string) $item->fresh()->meetup_reminders_sent);

        // Inside six hours: the 6h reminder fires, exactly once.
        $this->travelTo($meetup->copy()->subHours(5));
        $this->artisan('meetups:remind')->assertSuccessful();
        $this->artisan('meetups:remind')->assertSuccessful();
        $this->assertSame('360', $item->fresh()->meetup_reminders_sent);

        // Inside the hour, then inside thirty minutes.
        $this->travelTo($meetup->copy()->subMinutes(50));
        $this->artisan('meetups:remind')->assertSuccessful();
        $this->assertSame('360,60', $item->fresh()->meetup_reminders_sent);

        $this->travelTo($meetup->copy()->subMinutes(20));
        $this->artisan('meetups:remind')->assertSuccessful();
        $this->assertSame('360,60,30', $item->fresh()->meetup_reminders_sent);

        // A new time starts the ledger over.
        $this->actingAs($admin)
            ->postJson("/api/admin/items/{$item->item_id}/meetup", [
                'meetup_schedule' => $meetup->copy()->addDay()->toDateTimeString(),
            ])->assertOk();
        $this->assertNull($item->fresh()->meetup_reminders_sent);
    }

    #[Test]
    public function a_seller_still_sees_the_price_once_their_item_is_reserved(): void
    {
        // A reserved item takes the seller off the "purchasable" path and on
        // to their own view - which used to omit the public price entirely,
        // blanking it in the chat header and on View Item.
        $item = $this->publishedItem('250', '180');
        $seller = User::where('user_id', $item->seller_id)->firstOrFail();

        $item->update(['status' => Item::STATUS_RESERVED]);

        $data = $this->actingAs($seller)
            ->getJson("/api/items/{$item->item_id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('250.00', $data['public_price']);
        $this->assertSame('180.00', $data['acquisition_price']);

        // The store's profit stays the store's business.
        $this->assertArrayNotHasKey('markup', $data);
    }

    #[Test]
    public function a_seller_keeps_their_agreed_price_once_the_item_is_public(): void
    {
        // A published item is purchasable, so its own seller is handed the
        // buyer view - which carries no acquisition price. The chat header
        // then fell back to the asking price the student typed weeks ago.
        $item = $this->publishedItem('600', '400');
        $seller = User::where('user_id', $item->seller_id)->firstOrFail();

        $data = $this->actingAs($seller)
            ->getJson("/api/items/{$item->item_id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('400.00', $data['acquisition_price']);
        $this->assertSame('600.00', $data['public_price']);
        $this->assertTrue($data['offer_accepted']);

        // And the same figures survive the listing endpoint.
        $row = collect($this->actingAs($seller)->getJson('/api/items')->json('data'))
            ->firstWhere('item_id', $item->item_id);

        $this->assertSame('400.00', $row['acquisition_price']);
    }

    #[Test]
    public function a_buyer_never_sees_what_the_store_paid(): void
    {
        $item = $this->publishedItem('600', '400');
        $buyer = $this->student();

        $data = $this->actingAs($buyer)
            ->getJson("/api/items/{$item->item_id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('600.00', $data['public_price']);
        $this->assertArrayNotHasKey('acquisition_price', $data);
        $this->assertArrayNotHasKey('markup', $data);
    }
}
