<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * Section 1: a student seller uploads an item priced in pesos, and cannot
 * publish it themselves.
 */
class ItemUploadTest extends MarketplaceTestCase
{
    #[Test]
    public function a_student_uploads_an_item_with_a_200_peso_asking_price_and_it_becomes_pending(): void
    {
        $seller = $this->student();
        $category = $this->category();

        $response = $this->actingAs($seller)->postJson('/api/items', [
            'title' => 'Scientific Calculator',
            'description' => 'Barely used, complete with case.',
            'category_id' => $category->category_id,
            'seller_asking_price' => '200',
            'photos' => [UploadedFile::fake()->image('calculator.jpg')],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', Item::STATUS_PENDING);
        $response->assertJsonPath('data.seller_asking_price', '200.00');

        $item = Item::first();
        $this->assertSame('200.00', $item->seller_asking_price);
        $this->assertSame(Item::STATUS_PENDING, $item->status);
        // Money is stored as an exact decimal, never a float.
        $this->assertSame(20000, $item->askingPrice()->centavos());
    }

    #[Test]
    public function the_seller_sees_no_reward_points_on_their_pending_listing(): void
    {
        $seller = $this->student();
        $category = $this->category();

        $response = $this->actingAs($seller)->postJson('/api/items', [
            'title' => 'Lab Gown',
            'description' => 'Size M.',
            'category_id' => $category->category_id,
            'seller_asking_price' => '200',
            'photos' => [UploadedFile::fake()->image('gown.jpg')],
        ]);

        // Reward points are a buyer-side concept. Nothing on the seller's own
        // pending listing may present a points equivalent of their price.
        $response->assertJsonMissingPath('data.reward_points');
        $response->assertJsonMissingPath('data.public_price');
        $response->assertJsonMissingPath('data.markup_points');

        $listing = $this->actingAs($seller)->getJson('/api/items?status=pending');
        $listing->assertOk();
        $listing->assertJsonMissingPath('data.0.reward_points');
        $listing->assertJsonPath('data.0.seller_asking_price', '200.00');
    }

    #[Test]
    public function a_student_cannot_publish_their_own_listing(): void
    {
        $seller = $this->student();
        $item = Item::factory()->for($seller, 'seller')->create();

        // The previous version of this endpoint accepted `status` and would
        // happily set a student's own item to public.
        $response = $this->actingAs($seller)->putJson("/api/items/{$item->item_id}", [
            'status' => Item::STATUS_PUBLIC,
        ]);

        $response->assertOk();
        $this->assertSame(Item::STATUS_PENDING, $item->fresh()->status);
    }

    #[Test]
    public function a_student_can_edit_and_delete_a_pending_listing(): void
    {
        $seller = $this->student();
        $item = Item::factory()->for($seller, 'seller')->create();

        $this->actingAs($seller)
            ->putJson("/api/items/{$item->item_id}", [
                'title' => 'Updated title',
                'seller_asking_price' => '175.50',
            ])
            ->assertOk()
            ->assertJsonPath('data.seller_asking_price', '175.50');

        $this->actingAs($seller)
            ->deleteJson("/api/items/{$item->item_id}")
            ->assertOk();

        $this->assertDatabaseMissing('items', ['item_id' => $item->item_id]);
    }

    #[Test]
    public function a_student_cannot_edit_someone_elses_listing(): void
    {
        $item = Item::factory()->for($this->student(), 'seller')->create();

        $this->actingAs($this->student())
            ->putJson("/api/items/{$item->item_id}", ['title' => 'Hijacked'])
            ->assertStatus(403);
    }

    #[Test]
    public function an_asking_price_must_be_a_valid_peso_amount(): void
    {
        $seller = $this->student();
        $category = $this->category();

        $this->actingAs($seller)->postJson('/api/items', [
            'title' => 'Broken price',
            'description' => 'Test',
            'category_id' => $category->category_id,
            'seller_asking_price' => '200.999',
            'photos' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertStatus(422);
    }

    #[Test]
    public function uploaded_photos_must_be_images_within_the_size_limit(): void
    {
        $seller = $this->student();
        $category = $this->category();

        $this->actingAs($seller)->postJson('/api/items', [
            'title' => 'Bad upload',
            'description' => 'Test',
            'category_id' => $category->category_id,
            'seller_asking_price' => '100',
            'photos' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
        ])->assertStatus(422);
    }

    #[Test]
    public function an_older_app_build_sending_price_points_is_treated_as_pesos(): void
    {
        $seller = $this->student();
        $category = $this->category();

        // Backward compatibility for the currently deployed app, which still
        // posts `price_points`.
        $this->actingAs($seller)->postJson('/api/items', [
            'title' => 'Legacy client',
            'description' => 'Posted by an old build.',
            'category_id' => $category->category_id,
            'price_points' => 200,
            'photos' => [UploadedFile::fake()->image('legacy.jpg')],
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.seller_asking_price', '200.00');
    }
}
