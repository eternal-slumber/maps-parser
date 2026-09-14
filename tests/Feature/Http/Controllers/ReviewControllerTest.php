<?php

use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;

it('returns 401 when an unauthenticated user requests reviews', function () {
    $organization = Organization::factory()->create();

    $this->getJson(route('organizations.reviews.index', $organization))
        ->assertUnauthorized();
});

it('returns reviews 50 per page from newest to oldest', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->for($user)->create();
    Review::factory()
        ->count(51)
        ->for($organization)
        ->sequence(fn (Sequence $sequence): array => [
            'external_id' => "review-{$sequence->index}",
            'published_at' => now()->subMinutes($sequence->index),
        ])
        ->create();
    Review::factory()->for($organization)->create(['is_active' => false]);

    $this->actingAs($user)
        ->getJson(route('organizations.reviews.index', $organization))
        ->assertOk()
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('data.0.external_id', 'review-0')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.per_page', 50)
        ->assertJsonPath('meta.total', 51);

    $this->actingAs($user)
        ->getJson(route('organizations.reviews.index', [
            'organization' => $organization,
            'page' => 2,
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.external_id', 'review-50');
});

it('returns 404 for another users organization', function () {
    $organization = Organization::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->getJson(route('organizations.reviews.index', $organization))
        ->assertNotFound();
});

it('filters reviews by rating before pagination', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->for($user)->create();
    Review::factory()->count(3)->for($organization)->create(['rating' => 5]);
    Review::factory()->count(2)->for($organization)->create(['rating' => 4]);

    $this->actingAs($user)
        ->getJson(route('organizations.reviews.index', [
            'organization' => $organization,
            'rating' => 5,
        ]))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.rating', 5)
        ->assertJsonPath('data.1.rating', 5)
        ->assertJsonPath('data.2.rating', 5);
});

it('rejects an invalid rating filter', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->for($user)->create();

    $this->actingAs($user)
        ->getJson(route('organizations.reviews.index', [
            'organization' => $organization,
            'rating' => 6,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rating' => 'Оценка должна быть от 1 до 5.']);
});
