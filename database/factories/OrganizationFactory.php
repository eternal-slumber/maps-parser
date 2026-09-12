<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $businessId = fake()->unique()->numerify('############');

        return [
            'user_id' => User::factory(),
            'source_url' => "https://yandex.ru/maps/org/{$businessId}/reviews/",
            'business_id' => $businessId,
            'name' => fake()->company(),
            'rating' => fake()->randomFloat(2, 0, 5),
            'rating_count' => fake()->numberBetween(0, 10_000),
            'review_count' => fake()->numberBetween(0, 600),
            'sync_status' => 'pending',
            'processed_pages' => 0,
            'processed_reviews' => 0,
            'sync_error' => null,
            'last_synced_at' => null,
        ];
    }
}
