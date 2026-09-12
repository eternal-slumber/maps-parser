<?php

declare(strict_types=1);

use App\Services\YandexMapsParser;
use Illuminate\Support\Facades\Http;

it('extracts the business id from an organization link', function (string $url) {
    $businessId = app(YandexMapsParser::class)->extractBusinessId($url);

    expect($businessId)->toBe('134528915428');
})->with([
    'link with organization slug' => 'https://yandex.ru/maps/org/company_name/134528915428/reviews/',
    'link without organization slug' => 'https://yandex.ru/maps/org/134528915428/reviews/',
]);

it('rejects unsupported organization links', function (string $url) {
    expect(fn () => app(YandexMapsParser::class)->extractBusinessId($url))
        ->toThrow(InvalidArgumentException::class, 'Некорректная ссылка Яндекс Карт.');
})->with([
    'foreign host' => 'https://example.com/maps/org/company/134528915428/',
    'deceptive host' => 'https://yandex.ru.example.com/maps/org/company/134528915428/',
    'missing business id' => 'https://yandex.ru/maps/org/company/',
]);

it('collects unique reviews until a page repeats', function () {
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

    $reviews = app(YandexMapsParser::class)->fetchAll('123');

    expect(array_column($reviews, 'external_id'))->toBe([
        'review-1',
        'review-2',
    ]);
    Http::assertSentInOrder([
        'https://yandex.ru/maps/org/123/reviews/?page=1',
        'https://yandex.ru/maps/org/123/reviews/?page=2',
        'https://yandex.ru/maps/org/123/reviews/?page=3',
    ]);
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
