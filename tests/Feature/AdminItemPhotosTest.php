<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemPhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ofelia can add her own photos to a listing, and drop the seller's, until the
 * item goes on sale.
 */
class AdminItemPhotosTest extends MarketplaceTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin();
    }

    private function itemWithPhotos(int $count, string $status = Item::STATUS_PENDING): Item
    {
        $factory = Item::factory()->for($this->student(), 'seller');

        $item = match ($status) {
            Item::STATUS_ACQUIRED => $factory->acquired()->create(),
            Item::STATUS_PUBLIC => $factory->published()->create(),
            default => $factory->create(),
        };

        for ($i = 1; $i <= $count; $i++) {
            ItemPhoto::create([
                'item_id' => $item->item_id,
                'photo_url' => "https://fake-cdn.test/items/seller-{$i}.jpg",
            ]);
        }

        return $item;
    }

    private function add(Item $item, array $photos)
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/admin/items/{$item->item_id}/photos", ['photos' => $photos]);
    }

    #[Test]
    public function the_photos_are_listed_with_their_ids(): void
    {
        $item = $this->itemWithPhotos(2);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/items/{$item->item_id}/photos")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame(
            $item->photos()->orderBy('photo_id')->pluck('photo_id')->all(),
            array_column($response->json('data'), 'photo_id'),
        );
    }

    #[Test]
    public function photos_can_be_added_to_a_pending_offer(): void
    {
        $item = $this->itemWithPhotos(1);

        $this->add($item, [
            UploadedFile::fake()->image('front.jpg'),
            UploadedFile::fake()->image('back.png'),
        ])->assertOk()->assertJsonCount(3, 'data');

        $this->assertCount(2, $this->uploader->uploaded);
        $this->assertSame(3, $item->photos()->count());
    }

    #[Test]
    public function photos_can_be_added_once_the_item_is_in_stock(): void
    {
        $item = $this->itemWithPhotos(1, Item::STATUS_ACQUIRED);

        $this->add($item, [UploadedFile::fake()->image('shelf.jpg')])
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_published_item_keeps_its_photos(): void
    {
        $item = $this->itemWithPhotos(2, Item::STATUS_PUBLIC);

        $this->add($item, [UploadedFile::fake()->image('late.jpg')])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Photos can only be changed before the item is published.');

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/items/{$item->item_id}/photos/{$item->photos()->first()->photo_id}")
            ->assertStatus(422);

        $this->assertSame(2, $item->photos()->count());
        $this->assertSame([], $this->uploader->uploaded);
    }

    #[Test]
    public function a_listing_holds_at_most_five_photos(): void
    {
        $item = $this->itemWithPhotos(4);

        $this->add($item, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ])->assertStatus(422);

        $this->add($item, [UploadedFile::fake()->image('a.jpg')])->assertOk()->assertJsonCount(5, 'data');
    }

    #[Test]
    public function only_images_are_accepted(): void
    {
        $item = $this->itemWithPhotos(1);

        $this->add($item, [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')])
            ->assertStatus(422);

        $this->assertSame(1, $item->photos()->count());
    }

    #[Test]
    public function a_photo_can_be_removed(): void
    {
        $item = $this->itemWithPhotos(2);
        $photo = $item->photos()->orderBy('photo_id')->first();

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/items/{$item->item_id}/photos/{$photo->photo_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertDatabaseMissing('item_photos', ['photo_id' => $photo->photo_id]);
    }

    #[Test]
    public function the_last_photo_stays(): void
    {
        $item = $this->itemWithPhotos(1);

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/items/{$item->item_id}/photos/{$item->photos()->first()->photo_id}")
            ->assertStatus(422);

        $this->assertSame(1, $item->photos()->count());
    }

    #[Test]
    public function a_photo_of_another_item_is_not_found(): void
    {
        $item = $this->itemWithPhotos(2);
        $other = $this->itemWithPhotos(2);

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/items/{$item->item_id}/photos/{$other->photos()->first()->photo_id}")
            ->assertNotFound();

        $this->assertSame(2, $other->photos()->count());
    }

    #[Test]
    public function students_cannot_touch_the_photos(): void
    {
        $item = $this->itemWithPhotos(2);

        $this->actingAs($this->student())
            ->getJson("/api/admin/items/{$item->item_id}/photos")
            ->assertForbidden();
    }
}
