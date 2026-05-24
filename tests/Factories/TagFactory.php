<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Vusys\QueryRicerExtreme\Tests\Models\Tag;

/**
 * @extends Factory<Tag>
 */
final class TagFactory extends Factory
{
    protected $model = Tag::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'priority' => fake()->numberBetween(0, 5),
            'color' => fake()->optional()->hexColor(),
        ];
    }
}
