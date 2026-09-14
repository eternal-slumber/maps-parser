<?php

declare(strict_types=1);

use App\Services\YandexMapsParser;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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
    $firstPageHtml = yandexStateHtml([[
        'type' => 'business',
        'id' => '134528915428',
        'title' => 'Дебри',
        'ratingData' => [
            'ratingCount' => 6299,
            'ratingValue' => 5,
            'reviewCount' => 2,
        ],
        'reviewResults' => ['reviews' => [[
            'reviewId' => 'review-1',
            'author' => ['name' => 'Артём'],
            'text' => 'Первый отзыв',
            'rating' => 5,
            'updatedTime' => '2026-09-11T12:00:00.000Z',
        ]]],
    ]]);
    $reviewHtml = fn (string $reviewId): string => yandexStateHtml([[
        'type' => 'business',
        'id' => '134528915428',
        'reviewResults' => ['reviews' => [[
            'reviewId' => $reviewId,
            'author' => ['name' => 'Иван'],
            'text' => 'Следующий отзыв',
            'rating' => 4,
            'updatedTime' => '2026-09-10T12:00:00.000Z',
        ]]],
    ]]);

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

it('rejects a partial fetchAll result when a page repeats', function () {
    $reviewHtml = fn (string $reviewId): string => yandexStateHtml([[
        'type' => 'business',
        'id' => '123',
        'reviewResults' => ['reviews' => [[
            'reviewId' => $reviewId,
            'author' => ['name' => 'Артём'],
            'text' => 'Отличное место',
            'rating' => 5,
            'updatedTime' => '2026-09-11T12:00:00.000Z',
        ]]],
    ]]);

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
    $review = [
        'reviewId' => 'review-1',
        'rating' => 5,
        'updatedTime' => '2026-09-11T12:00:00.000Z',
    ];
    $firstPageHtml = yandexStateHtml([[
        'type' => 'business',
        'id' => '134528915428',
        'title' => 'Дебри',
        'ratingData' => [
            'ratingCount' => 1000,
            'ratingValue' => 5,
            'reviewCount' => 600,
        ],
        'reviewResults' => ['reviews' => [$review]],
    ]]);
    $nextPageHtml = match ($ending) {
        'empty' => yandexStateHtml([[
            'type' => 'business',
            'id' => '134528915428',
            'reviewResults' => ['reviews' => []],
        ]]),
        'repeated' => yandexStateHtml([[
            'type' => 'business',
            'id' => '134528915428',
            'reviewResults' => ['reviews' => [$review]],
        ]]),
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
        $business = [
            'type' => 'business',
            'id' => '134528915428',
            'reviewResults' => ['reviews' => $reviews],
        ];

        if ($page === 1) {
            $business += [
                'title' => 'Дебри',
                'ratingData' => [
                    'ratingCount' => 6299,
                    'ratingValue' => 5,
                    'reviewCount' => 2508,
                ],
            ];
        }

        return Http::response(yandexStateHtml([$business]));
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
