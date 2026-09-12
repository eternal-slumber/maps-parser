<?php

use App\Jobs\SyncYandexOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('returns 401 when an unauthenticated user submits an organization', function () {
    Queue::fake([SyncYandexOrganization::class]);

    $this->postJson(route('organizations.store'), [
        'url' => 'https://yandex.ru/maps/org/debri/134528915428/reviews/',
    ])->assertUnauthorized();

    Queue::assertNothingPushed();
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
