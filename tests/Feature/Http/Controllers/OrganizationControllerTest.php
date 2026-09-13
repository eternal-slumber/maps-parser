<?php

use App\Jobs\SyncYandexOrganization;
use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('returns 401 when an unauthenticated user submits an organization', function () {
    Queue::fake([SyncYandexOrganization::class]);

    $this->postJson(route('organizations.store'), [
        'url' => 'https://yandex.ru/maps/org/debri/134528915428/reviews/',
    ])->assertUnauthorized();

    Queue::assertNothingPushed();
});

it('returns 401 when an unauthenticated user requests organizations', function () {
    $this->getJson(route('organizations.index'))->assertUnauthorized();
});

it('returns only the authenticated users organizations in recent order', function () {
    $user = User::factory()->create();
    $older = Organization::factory()->for($user)->create([
        'updated_at' => now()->subDay(),
    ]);
    $newer = Organization::factory()->for($user)->create([
        'updated_at' => now(),
    ]);
    Organization::factory()->create();

    $this->actingAs($user)
        ->getJson(route('organizations.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id);
});

it('rejects invalid organization links', function (array $payload, string $message) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('organizations.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['url' => $message]);
})->with([
    'missing link' => [[], 'Укажите ссылку на организацию.'],
    'non-string link' => [['url' => 123], 'Ссылка должна быть строкой.'],
    'malformed link' => [['url' => 'not-a-url'], 'Укажите корректную HTTP(S)-ссылку.'],
    'different website' => [
        ['url' => 'https://example.com/maps/org/134528915428'],
        'Укажите ссылку на организацию в Яндекс Картах.',
    ],
]);

it('stores an organization and dispatches its synchronization', function () {
    $user = User::factory()->create();
    Queue::fake([SyncYandexOrganization::class]);

    $response = $this->actingAs($user)->postJson(route('organizations.store'), [
        'url' => 'https://yandex.ru/maps/org/debri/134528915428/reviews/',
        'sync_status' => Organization::SYNC_COMPLETED,
    ]);

    $response
        ->assertAccepted()
        ->assertJsonPath('data.business_id', '134528915428')
        ->assertJsonPath('data.sync_status', Organization::SYNC_PENDING)
        ->assertJsonPath('data.processed_pages', 0)
        ->assertJsonPath('data.processed_reviews', 0);
    $this->assertDatabaseHas('organizations', [
        'user_id' => $user->id,
        'business_id' => '134528915428',
        'sync_status' => Organization::SYNC_PENDING,
    ]);
    Queue::assertPushed(
        SyncYandexOrganization::class,
        fn (SyncYandexOrganization $job): bool => $job->organizationId === $response->json('data.id'),
    );
});

it('accepts a short organization link and stores its canonical url', function () {
    $user = User::factory()->create();
    Queue::fake([SyncYandexOrganization::class]);
    Http::preventStrayRequests();
    Http::fake([
        'https://yandex.ru/maps/-/CTtm6ILS' => Http::response('', 301, [
            'Location' => '/maps/191/bryansk/?poi%5Buri%5D=ymapsbm1%3A%2F%2Forg%3Foid%3D137381017899',
        ]),
    ]);

    $this->actingAs($user)
        ->postJson(route('organizations.store'), [
            'url' => 'https://yandex.ru/maps/-/CTtm6ILS',
        ])
        ->assertAccepted()
        ->assertJsonPath('data.business_id', '137381017899')
        ->assertJsonPath('data.url', 'https://yandex.ru/maps/org/137381017899/');

    $this->assertDatabaseHas('organizations', [
        'user_id' => $user->id,
        'business_id' => '137381017899',
        'source_url' => 'https://yandex.ru/maps/org/137381017899/',
    ]);
    Queue::assertPushed(SyncYandexOrganization::class);
});

it('updates the same organization instead of creating a duplicate', function () {
    $user = User::factory()->create();
    Queue::fake([SyncYandexOrganization::class]);
    $url = 'https://yandex.ru/maps/org/debri/134528915428/reviews/';

    $this->actingAs($user)->postJson(route('organizations.store'), ['url' => $url]);
    $this->actingAs($user)->postJson(route('organizations.store'), ['url' => $url]);

    expect($user->organizations()->count())->toBe(1);
});

it('returns synchronization status to the organization owner', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->for($user)->create([
        'sync_status' => Organization::SYNC_PROCESSING,
        'processed_pages' => 3,
        'processed_reviews' => 150,
    ]);

    $this->actingAs($user)
        ->getJson(route('organizations.show', $organization))
        ->assertOk()
        ->assertJsonPath('data.id', $organization->id)
        ->assertJsonPath('data.sync_status', Organization::SYNC_PROCESSING)
        ->assertJsonPath('data.processed_pages', 3)
        ->assertJsonPath('data.processed_reviews', 150);
});

it('returns 404 for another users organization', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $organization = Organization::factory()->for($owner)->create();

    $this->actingAs($otherUser)
        ->getJson(route('organizations.show', $organization))
        ->assertNotFound();
});

it('returns 401 when an unauthenticated user deletes an organization', function () {
    $organization = Organization::factory()->create();

    $this->deleteJson(route('organizations.destroy', $organization))
        ->assertUnauthorized();

    $this->assertModelExists($organization);
});

it('deletes an organization owned by the authenticated user', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->for($user)->create();
    $review = Review::factory()->for($organization)->create();

    $this->actingAs($user)
        ->deleteJson(route('organizations.destroy', $organization))
        ->assertNoContent();

    $this->assertModelMissing($organization);
    $this->assertModelMissing($review);
});

it('returns 404 when deleting another users organization', function () {
    $organization = Organization::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->deleteJson(route('organizations.destroy', $organization))
        ->assertNotFound();

    $this->assertModelExists($organization);
});
