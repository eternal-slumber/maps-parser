<?php

declare(strict_types=1);

use App\Services\YandexMapsParser;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('extracts the business id from an organization link', function (string $url) {
    $businessId = app(YandexMapsParser::class)->extractBusinessId($url);

    expect($businessId)->toBe('134528915428');
})->with([
    'link with organization slug' => 'https://yandex.ru/maps/org/company_name/134528915428/reviews/',
    'link without organization slug' => 'https://yandex.ru/maps/org/134528915428/reviews/',
]);

it('resolves the business id from a short organization link', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://yandex.ru/maps/-/CTtm6ILS' => Http::response('', 301, [
            'Location' => '/maps/191/bryansk/?poi%5Buri%5D=ymapsbm1%3A%2F%2Forg%3Foid%3D137381017899',
        ]),
    ]);

    $businessId = app(YandexMapsParser::class)->resolveBusinessId(
        'https://yandex.ru/maps/-/CTtm6ILS',
    );

    expect($businessId)->toBe('137381017899');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD');
});

it('rejects unsupported organization links', function (string $url) {
    expect(fn () => app(YandexMapsParser::class)->extractBusinessId($url))
        ->toThrow(InvalidArgumentException::class, 'Некорректная ссылка Яндекс Карт.');
})->with([
    'foreign host' => 'https://example.com/maps/org/company/134528915428/',
    'deceptive host' => 'https://yandex.ru.example.com/maps/org/company/134528915428/',
    'non-maps path' => 'https://yandex.ru/search/org/company/134528915428/',
    'missing business id' => 'https://yandex.ru/maps/org/company/',
    'unsupported scheme' => 'ftp://yandex.ru/maps/-/CTtm6ILS',
]);

it('returns organization data from embedded state', function () {
    $html = <<<'HTML'
    <script type="application/json" class="state-view">
    {
        "stack": [{
            "results": {
                "items": [
                    {
                        "type": "business",
                        "id": "999",
                        "title": "Другая организация",
                        "ratingData": {"ratingCount": 1, "ratingValue": 1, "reviewCount": 1}
                    },
                    {
                        "type": "business",
                        "id": "134528915428",
                        "title": "Дебри",
                        "ratingData": {"ratingCount": 6299, "ratingValue": 5, "reviewCount": 2508}
                    }
                ]
            }
        }]
    }
    </script>
    HTML;

    Http::preventStrayRequests();
    Http::fake([
        'https://yandex.ru/maps/org/134528915428/reviews/?page=1' => Http::response($html),
    ]);

    $organization = app(YandexMapsParser::class)->fetchOrganization('134528915428');

    expect($organization)->toBe([
        'business_id' => '134528915428',
        'name' => 'Дебри',
        'rating' => 5.0,
        'rating_count' => 6299,
        'review_count' => 2508,
    ]);
    Http::assertSentInOrder([
        'https://yandex.ru/maps/org/134528915428/reviews/?page=1',
    ]);
});

it('rejects incomplete organization data', function () {
    $html = <<<'HTML'
    <script type="application/json" class="state-view">
    {"stack":[{"results":{"items":[{
        "type":"business",
        "id":"134528915428",
        "title":"Дебри",
        "ratingData":{"ratingCount":6299,"ratingValue":5}
    }]}}]}
    </script>
    HTML;

    Http::preventStrayRequests();
    Http::fake([
        'https://yandex.ru/maps/org/134528915428/reviews/?page=1' => Http::response($html),
    ]);

    expect(fn () => app(YandexMapsParser::class)->fetchOrganization('134528915428'))
        ->toThrow(RuntimeException::class, 'Данные организации неполные.');
    Http::assertSentInOrder([
        'https://yandex.ru/maps/org/134528915428/reviews/?page=1',
    ]);
});

it('rejects organization data with invalid types', function () {
    $html = <<<'HTML'
    <script type="application/json" class="state-view">
    {"stack":[{"results":{"items":[{
        "type":"business",
        "id":"134528915428",
        "title":"Дебри",
        "ratingData":{"ratingCount":"6299","ratingValue":5,"reviewCount":2508}
    }]}}]}
    </script>
    HTML;

    Http::preventStrayRequests();
    Http::fake([
        'https://yandex.ru/maps/org/134528915428/reviews/?page=1' => Http::response($html),
    ]);

    expect(fn () => app(YandexMapsParser::class)->fetchOrganization('134528915428'))
        ->toThrow(RuntimeException::class, 'Данные организации имеют некорректный формат.');
    Http::assertSentInOrder([
        'https://yandex.ru/maps/org/134528915428/reviews/?page=1',
    ]);
});

it('returns no reviews when the organization has none', function () {
    $html = <<<'HTML'
    <script type="application/json" class="state-view">
    {"stack":[{"results":{"items":[{
        "type":"business",
        "id":"134528915428",
        "title":"Новая организация",
        "ratingData":{"ratingCount":0,"ratingValue":0,"reviewCount":0}
    }]}}]}
    </script>
    HTML;

    Http::preventStrayRequests();
    Http::fake([
        'https://yandex.ru/maps/org/134528915428/reviews/?page=1' => Http::response($html),
    ]);

    $result = app(YandexMapsParser::class)->fetch(
        'https://yandex.ru/maps/org/new_company/134528915428/',
    );

    expect($result['reviews'])->toBe([]);
    expect($result['organization']['review_count'])->toBe(0);
    expect($result['collection'])->toBe([
        'status' => YandexMapsParser::COLLECTION_COMPLETE,
        'stop_reason' => 'reported_total_reached',
    ]);
    Http::assertSentInOrder([
        'https://yandex.ru/maps/org/134528915428/reviews/?page=1',
    ]);
});

it('rejects unusable pages', function (string $html, string $message) {
    Http::preventStrayRequests();
    Http::fake([
        'https://yandex.ru/maps/org/134528915428/reviews/?page=1' => Http::response($html),
    ]);

    expect(fn () => app(YandexMapsParser::class)->fetchOrganization('134528915428'))
        ->toThrow(RuntimeException::class, $message);
    Http::assertSentInOrder([
        'https://yandex.ru/maps/org/134528915428/reviews/?page=1',
    ]);
})->with([
    'empty response' => ['', 'Яндекс вернул пустую страницу.'],
    'captcha response' => [
        '<html><form action="/checkcaptcha">Подтвердите запрос</form></html>',
        'Яндекс заблокировал запрос или запросил captcha.',
    ],
]);

it('stops at the reported review count without downloading the first page twice', function () {
    $firstPageHtml = <<<'HTML'
    <script type="application/json" class="state-view">
    {"stack":[{"results":{"items":[{
        "type":"business",
        "id":"134528915428",
        "title":"Дебри",
        "ratingData":{"ratingCount":6299,"ratingValue":5,"reviewCount":2}
    }]}}]}
    </script>
    <script>{"reviews":[{
        "reviewId":"review-1",
        "author":{"name":"Артём"},
        "text":"Первый отзыв",
        "rating":5,
        "updatedTime":"2026-09-11T12:00:00.000Z"
    }]}</script>
    HTML;
    $reviewHtml = fn (string $reviewId): string => <<<HTML
    <script>{"reviews":[{
        "reviewId":"{$reviewId}",
        "author":{"name":"Иван"},
        "text":"Следующий отзыв",
        "rating":4,
        "updatedTime":"2026-09-10T12:00:00.000Z"
    }]}</script>
    HTML;

    Http::preventStrayRequests();
    Http::fakeSequence('https://yandex.ru/maps/org/134528915428/reviews/*')
        ->push($firstPageHtml)
        ->push($reviewHtml('review-2'));

    $result = app(YandexMapsParser::class)->fetch(
        'https://yandex.ru/maps/org/debri/134528915428/reviews/',
    );

    expect($result['organization'])->toBe([
        'business_id' => '134528915428',
        'name' => 'Дебри',
        'rating' => 5.0,
        'rating_count' => 6299,
        'review_count' => 2,
    ]);
    expect(array_column($result['reviews'], 'external_id'))->toBe([
        'review-1',
        'review-2',
    ]);
    expect($result['collection'])->toBe([
        'status' => YandexMapsParser::COLLECTION_COMPLETE,
        'stop_reason' => 'reported_total_reached',
    ]);
    Http::assertSentInOrder([
        'https://yandex.ru/maps/org/134528915428/reviews/?page=1',
        'https://yandex.ru/maps/org/134528915428/reviews/?page=2',
    ]);
});

it('returns no reviews from an explicitly empty reviews array', function () {
    $reviews = app(YandexMapsParser::class)->parseHtml(
        '<script>{"reviewResults":{"reviews":[]}}</script>',
    );

    expect($reviews)->toBe([]);
});

it('rejects a partial fetchAll result when a page repeats', function () {
    $reviewHtml = fn (string $reviewId): string => <<<HTML
    <script>
    {
        "reviews": [
            {
                "reviewId": "{$reviewId}",
                "author": {"name": "Артём"},
                "text": "Отличное место",
                "rating": 5,
                "updatedTime": "2026-09-11T12:00:00.000Z"
            }
        ]
    }
    </script>
    HTML;

    Http::preventStrayRequests();
    Http::fakeSequence('https://yandex.ru/maps/org/123/reviews/*')
        ->push($reviewHtml('review-1'))
        ->push($reviewHtml('review-2'))
        ->push($reviewHtml('review-2'));

    expect(fn () => app(YandexMapsParser::class)->fetchAll('123'))
        ->toThrow(RuntimeException::class, 'Сбор отзывов подозрительно оборвался: repeated_page.');
    Http::assertSentInOrder([
        'https://yandex.ru/maps/org/123/reviews/?page=1',
        'https://yandex.ru/maps/org/123/reviews/?page=2',
        'https://yandex.ru/maps/org/123/reviews/?page=3',
    ]);
});

it('reports empty, repeated, and max-page stops as suspicious', function (
    string $ending,
    int $maxPages,
    int $requestCount,
    string $stopReason,
) {
    $review = <<<'JSON'
    {
        "reviewId":"review-1",
        "rating":5,
        "updatedTime":"2026-09-11T12:00:00.000Z"
    }
    JSON;
    $firstPageHtml = <<<HTML
    <script class="state-view">{"stack":[{"results":{"items":[{
        "type":"business",
        "id":"134528915428",
        "title":"Дебри",
        "ratingData":{"ratingCount":1000,"ratingValue":5,"reviewCount":600}
    }]}}]}</script>
    <script>{"reviews":[{$review}]}</script>
    HTML;
    $nextPageHtml = match ($ending) {
        'empty' => '<script>{"reviews":[]}</script>',
        'repeated' => "<script>{\"reviews\":[{$review}]}</script>",
        'max_pages' => null,
    };

    Http::preventStrayRequests();
    $sequence = Http::fakeSequence('https://yandex.ru/maps/org/134528915428/reviews/*')
        ->push($firstPageHtml);

    if ($nextPageHtml !== null) {
        $sequence->push($nextPageHtml);
    }

    $result = app(YandexMapsParser::class)->fetch(
        'https://yandex.ru/maps/org/134528915428/',
        $maxPages,
    );

    expect($result['reviews'])->toHaveCount(1);
    expect($result['collection'])->toBe([
        'status' => YandexMapsParser::COLLECTION_SUSPICIOUS,
        'stop_reason' => $stopReason,
    ]);
    Http::assertSentCount($requestCount);
})->with([
    'empty page' => ['empty', 20, 2, 'empty_page'],
    'repeated page' => ['repeated', 20, 2, 'repeated_page'],
    'max pages' => ['max_pages', 1, 1, 'max_pages_reached'],
]);

it('reports the Yandex limit when fewer reviews are available than reported', function () {
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

    $result = app(YandexMapsParser::class)->fetch(
        'https://yandex.ru/maps/org/134528915428/',
        20,
    );

    expect($result['reviews'])->toHaveCount(600);
    expect($result['collection'])->toBe([
        'status' => YandexMapsParser::COLLECTION_SOURCE_LIMITED,
        'stop_reason' => 'source_limit_reached',
    ]);
    Http::assertSentCount(12);
});

it('extracts normalized reviews from embedded JSON', function () {
    $html = <<<'HTML'
    <script>
    {
        "reviews": [
            {
                "reviewId": "review-123",
                "author": {"name": "Артём"},
                "text": "Отличное место",
                "rating": 5,
                "updatedTime": "2026-09-11T12:00:00.000Z"
            }
        ]
    }
    </script>
    HTML;

    $reviews = app(YandexMapsParser::class)->parseHtml($html);

    expect($reviews)->toBe([
        [
            'external_id' => 'review-123',
            'author_name' => 'Артём',
            'text' => 'Отличное место',
            'rating' => 5,
            'updated_time' => '2026-09-11T12:00:00.000Z',
        ],
    ]);
});

it('rejects reviews with invalid types', function () {
    $html = <<<'HTML'
    <script>{"reviews":[{
        "reviewId":"review-123",
        "author":{"name":"Артём"},
        "text":"Отличное место",
        "rating":"5",
        "updatedTime":"2026-09-11T12:00:00.000Z"
    }]}</script>
    HTML;

    expect(fn () => app(YandexMapsParser::class)->parseHtml($html))
        ->toThrow(RuntimeException::class, 'Отзыв имеет некорректный формат.');
});
