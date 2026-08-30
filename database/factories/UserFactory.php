<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * The live `users` table has no `name` column - student names live in
     * student_information - and carries wallet_points, role and is_active
     * instead.
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->userName() . '@student.fatima.edu.ph',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'wallet_points' => 0,
            'role' => User::ROLE_STUDENT,
            'is_active' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_ADMIN,
            'email' => fake()->unique()->userName() . '@fatima.edu.ph',
        ]);
    }

    /** A buyer holding a given number of loyalty points. */
    public function withPoints(int $points): static
    {
        return $this->state(fn (array $attributes) => ['wallet_points' => $points]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
