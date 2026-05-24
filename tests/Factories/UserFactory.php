<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Vusys\QueryRicerExtreme\Tests\Models\User;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'active' => true,
            'score' => fake()->optional()->numberBetween(0, 100),
            'bio' => fake()->optional()->sentence(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }

    public function softDeleted(): static
    {
        return $this->afterCreating(fn (User $user) => $user->delete());
    }
}
