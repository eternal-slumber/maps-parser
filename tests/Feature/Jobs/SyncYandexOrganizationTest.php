<?php

use App\Jobs\SyncYandexOrganization;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;

it('stores parser results and updates existing reviews without duplicates', function () {
    $organizationPage = fn (float $rating, string $reviewText): string => <<<HTML
    <script class="state-view">{"stack":[{"results":{"items":[{
        "type":"business",
        "id":"134528915428",
        "title":"Дебри",
        "ratingData":{"ratingCount":100,"ratingValue":{$rating},"reviewCount":2}
    }]}}]}</script>
    <script>{"reviews":[{
        "reviewId":"review-1",
        "author":{"name":"Артём"},
        "text":"{$reviewText}",
        "rating":5,
        "updatedTime":"2026-09-11T12:00:00.000Z"
    }]}</script>
    HTML;

    $reviewPage = fn (string $reviewId): string => <<<HTML
    <script>{"reviews":[{
        "reviewId":"{$reviewId}",
        "author":{"name":"Иван"},
        "text":"Второй отзыв",
        "rating":4,
        "updatedTime":"2026-09-10T12:00:00.000Z"
    }]}</script>
    HTML;

    Http::preventStrayRequests();
    Http::fakeSequence('https://yandex.ru/maps/org/134528915428/reviews/*')
        ->push($organizationPage(4.2, 'Первоначальный текст'))
        ->push($reviewPage('review-2'))
        ->push($reviewPage('review-2'))
        ->push($organizationPage(4.5, 'Обновлённый текст'))
        ->push($reviewPage('review-2'))
        ->push($reviewPage('review-2'));

    $organization = Organization::factory()->create([
        'source_url' => 'https://yandex.ru/maps/org/debri/134528915428/reviews/',
        'business_id' => '134528915428',
        'name' => null,
    ]);

    SyncYandexOrganization::dispatchSync($organization->id);

    expect($organization->refresh())
        ->name->toBe('Дебри')
        ->rating->toBe(4.2)
        ->rating_count->toBe(100)
        ->review_count->toBe(2)
        ->sync_status->toBe(Organization::SYNC_COMPLETED)
        ->processed_pages->toBe(2)
        ->processed_reviews->toBe(2)
        ->sync_error->toBeNull()
        ->last_synced_at->not->toBeNull();
    expect($organization->reviews()->count())->toBe(2);

    SyncYandexOrganization::dispatchSync($organization->id);

    expect($organization->refresh()->rating)->toBe(4.5);
    expect($organization->reviews()->count())->toBe(2);
    expect($organization->reviews()->where('external_id', 'review-1')->value('text'))
        ->toBe('Обновлённый текст');
});

it('records the final queue failure on the organization', function () {
    $organization = Organization::factory()->create([
        'sync_status' => Organization::SYNC_PROCESSING,
    ]);
    $job = new SyncYandexOrganization($organization->id);

    $job->failed(new RuntimeException('Яндекс временно недоступен.'));

    expect($organization->refresh())
        ->sync_status->toBe(Organization::SYNC_FAILED)
        ->sync_error->toBe('Яндекс временно недоступен.');
});
