<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Item;
use App\Models\User;
use App\Support\LoyaltyRules;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    /** A freshly uploaded listing: pending, priced in pesos, nothing else set. */
    public function definition(): array
    {
        $asking = fake()->numberBetween(50, 2000);

        return [
            'seller_id' => User::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'category_id' => Category::factory(),
            'status' => Item::STATUS_PENDING,
            'seller_asking_price' => number_format($asking, 2, '.', ''),
            'acquisition_price' => null,
            'public_price' => null,
            'reward_points' => 0,
            'seller_payout_status' => Item::PAYOUT_UNPAID,
            'price_source' => 'cash',
            'price_points' => $asking,
            'markup_points' => 0,
        ];
    }

    /** Physically received and verified by an admin. */
    public function acquired(int|string $acquisitionPrice = 180, ?User $admin = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Item::STATUS_ACQUIRED,
            'acquisition_price' => Money::fromPesos($acquisitionPrice)->toDecimalString(),
            'acquired_at' => now(),
            'acquired_by' => $admin?->user_id ?? User::factory()->admin(),
            'seller_payout_amount' => Money::fromPesos($acquisitionPrice)->toDecimalString(),
        ]);
    }

    /** Published to the buyer catalog at a peso price. */
    public function published(int|string $publicPrice = 250, int|string $acquisitionPrice = 180): static
    {
        $public = Money::fromPesos($publicPrice);

        return $this->acquired($acquisitionPrice)->state(fn (array $attributes) => [
            'status' => Item::STATUS_PUBLIC,
            'public_price' => $public->toDecimalString(),
            'reward_points' => LoyaltyRules::rewardPointsFor($public),
            'published_at' => now(),
            'markup_points' => intdiv($public->centavos(), 100),
        ]);
    }

    /** A legacy row whose peso price was converted from the old point value. */
    public function legacyPriced(): static
    {
        return $this->state(fn (array $attributes) => ['price_source' => 'legacy_points']);
    }
}
