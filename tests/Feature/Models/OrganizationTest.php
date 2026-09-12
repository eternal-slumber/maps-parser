<?php

use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\QueryException;

it('connects organizations, users, and reviews', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->for($user)->create();
    $review = Review::factory()->for($organization)->create();

    expect($organization->user->is($user))->toBeTrue();
    expect($organization->reviews->first()?->is($review))->toBeTrue();
    expect($review->organization->is($organization))->toBeTrue();
});

it('deletes reviews when their organization is deleted', function () {
    $review = Review::factory()->create();

    $review->organization->delete();

    $this->assertModelMissing($review);
});

it('rejects duplicate business ids', function () {
    $organization = Organization::factory()->create([
        'business_id' => '134528915428',
    ]);

    expect(fn () => Organization::factory()->create([
        'business_id' => $organization->business_id,
    ]))->toThrow(QueryException::class);
});

it('rejects duplicate review ids only within one organization', function () {
    $firstOrganization = Organization::factory()->create();
    $secondOrganization = Organization::factory()->create();
    Review::factory()->for($firstOrganization)->create([
        'external_id' => 'review-1',
    ]);

    $sameExternalReview = Review::factory()->for($secondOrganization)->create([
        'external_id' => 'review-1',
    ]);

    $this->assertModelExists($sameExternalReview);
    expect(fn () => Review::factory()->for($firstOrganization)->create([
        'external_id' => 'review-1',
    ]))->toThrow(QueryException::class);
});
