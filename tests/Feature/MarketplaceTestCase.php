<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\User;
use App\Services\PhotoUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePhotoUploader;
use Tests\TestCase;

/**
 * Shared setup for the marketplace feature tests: a schema rebuilt from the
 * migrations, and Cloudinary swapped for a local fake.
 */
abstract class MarketplaceTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakePhotoUploader $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploader = new FakePhotoUploader();
        $this->app->instance(PhotoUploader::class, $this->uploader);
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    protected function student(int $points = 0): User
    {
        return User::factory()->withPoints($points)->create();
    }

    protected function category(): Category
    {
        return Category::factory()->create();
    }

    /** A listing already published to the buyer catalog. */
    protected function publishedItem(string $publicPrice = '250', string $acquisitionPrice = '180', ?User $seller = null): Item
    {
        return Item::factory()
            ->published($publicPrice, $acquisitionPrice)
            ->for($seller ?? $this->student(), 'seller')
            ->create();
    }
}
