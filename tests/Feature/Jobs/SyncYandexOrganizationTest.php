<?php

use App\Jobs\SyncYandexOrganization;
use App\Models\Organization;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
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
        ->push($organizationPage(4.5, 'Обновлённый текст'))
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

it('does not complete a suspiciously truncated import', function () {
    $firstPageHtml = <<<'HTML'
    <script class="state-view">{"stack":[{"results":{"items":[{
        "type":"business",
        "id":"134528915428",
        "title":"Дебри",
        "ratingData":{"ratingCount":1000,"ratingValue":5,"reviewCount":600}
    }]}}]}</script>
    <script>{"reviews":[{
        "reviewId":"review-1",
        "rating":5,
        "updatedTime":"2026-09-11T12:00:00.000Z"
    }]}</script>
    HTML;

    Http::preventStrayRequests();
    Http::fakeSequence('https://yandex.ru/maps/org/134528915428/reviews/*')
        ->push($firstPageHtml)
        ->push('<script>{"reviews":[]}</script>');
    $organization = Organization::factory()->create([
        'source_url' => 'https://yandex.ru/maps/org/134528915428/',
        'business_id' => '134528915428',
    ]);

    expect(fn () => SyncYandexOrganization::dispatchSync($organization->id))
        ->toThrow(RuntimeException::class, 'Сбор отзывов подозрительно оборвался');

    expect($organization->refresh())
        ->sync_status->toBe(Organization::SYNC_FAILED)
        ->sync_error->toContain('Сбор отзывов подозрительно оборвался');
    expect($organization->reviews()->count())->toBe(0);
});

it('marks an import as limited when Yandex provides 600 of more reported reviews', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request): PromiseInterface {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);
        $reviews = array_map(
            fn (int $index): array => [
                'reviewId' => "review-{$page}-{$index}",
                'rating' => 5,
                'updatedTime' => '2026-09-11T12:00:00.000Z',
            ],
            range(1, 50),
        );
        $state = $page === 1
            ? '<script class="state-view">{"stack":[{"results":{"items":[{"type":"business","id":"134528915428","title":"Дебри","ratingData":{"ratingCount":6299,"ratingValue":5,"reviewCount":2508}}]}}]}</script>'
            : '';

        return Http::response($state.'<script>'.json_encode(['reviews' => $reviews], JSON_THROW_ON_ERROR).'</script>');
    });
    $organization = Organization::factory()->create([
        'source_url' => 'https://yandex.ru/maps/org/134528915428/',
        'business_id' => '134528915428',
    ]);

    SyncYandexOrganization::dispatchSync($organization->id);

    expect($organization->refresh())
        ->sync_status->toBe(Organization::SYNC_LIMITED)
        ->processed_pages->toBe(12)
        ->processed_reviews->toBe(600);
    expect($organization->reviews()->count())->toBe(600);
});

it('ignores a queued synchronization after its organization was deleted', function () {
    Http::preventStrayRequests();
    $organization = Organization::factory()->create();
    $organizationId = $organization->id;
    $organization->delete();

    SyncYandexOrganization::dispatchSync($organizationId);

    $this->assertModelMissing($organization);
});
