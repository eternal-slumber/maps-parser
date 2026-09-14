<?php

declare(strict_types=1);

use App\Services\YandexMapsPageParser;

it('parses an anonymized real Yandex SSR page', function () {
    $result = app(YandexMapsPageParser::class)->parse(
        (string) file_get_contents(base_path('tests/Fixtures/Yandex/reviews-page.html')),
        '123',
    );

    expect($result['organization'])->toBe([
        'business_id' => '123',
        'name' => 'Anonymous organization',
        'rating' => 4.5,
        'rating_count' => 100,
        'review_count' => 50,
    ]);
    expect($result['reviews'])->toHaveCount(50);
    expect($result['reviews'][0])->toBe([
        'external_id' => 'anonymous-review-01',
        'author_name' => 'anonymized',
        'text' => 'anonymized',
        'rating' => 1,
        'updated_time' => '2026-01-01T00:00:00.000Z',
    ]);
});

it('returns no reviews from an explicitly empty reviews array', function () {
    $reviews = app(YandexMapsPageParser::class)->parseReviews(
        yandexStateHtml([[
            'type' => 'business',
            'id' => '123',
            'reviewResults' => ['reviews' => []],
        ]]),
        '123',
    );

    expect($reviews)->toBe([]);
});

it('extracts normalized reviews for the requested business', function () {
    $html = yandexStateHtml([
        [
            'type' => 'business',
            'id' => '999',
            'reviewResults' => ['reviews' => [
                [
                    'reviewId' => 'unrelated-review-1',
                    'rating' => 1,
                    'updatedTime' => '2026-09-09T12:00:00.000Z',
                ],
                [
                    'reviewId' => 'unrelated-review-2',
                    'rating' => 1,
                    'updatedTime' => '2026-09-08T12:00:00.000Z',
                ],
            ]],
        ],
        [
            'type' => 'business',
            'id' => '123',
            'reviewResults' => ['reviews' => [[
                'reviewId' => 'review-123',
                'author' => ['name' => 'Артём'],
                'text' => 'Отличное место',
                'rating' => 5,
                'updatedTime' => '2026-09-11T12:00:00.000Z',
            ]]],
        ],
    ]);

    $reviews = app(YandexMapsPageParser::class)->parseReviews($html, '123');

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

it('rejects multiple review arrays inside the requested business', function () {
    $review = [
        'reviewId' => 'review-123',
        'rating' => 5,
        'updatedTime' => '2026-09-11T12:00:00.000Z',
    ];
    $html = yandexStateHtml([[
        'type' => 'business',
        'id' => '123',
        'reviewResults' => ['reviews' => [$review]],
        'anotherBlock' => ['reviews' => [$review]],
    ]]);

    expect(fn () => app(YandexMapsPageParser::class)->parseReviews($html, '123'))
        ->toThrow(UnexpectedValueException::class, 'Найдено несколько массивов отзывов организации.');
});

it('rejects multiple businesses with the requested id', function () {
    $business = [
        'type' => 'business',
        'id' => '123',
        'reviewResults' => ['reviews' => []],
    ];
    $html = yandexStateHtml([$business, $business]);

    expect(fn () => app(YandexMapsPageParser::class)->parseReviews($html, '123'))
        ->toThrow(RuntimeException::class, 'Найдено несколько организаций с указанным businessId.');
});

it('rejects reviews with invalid types', function () {
    $html = yandexStateHtml([[
        'type' => 'business',
        'id' => '123',
        'reviewResults' => ['reviews' => [[
            'reviewId' => 'review-123',
            'author' => ['name' => 'Артём'],
            'text' => 'Отличное место',
            'rating' => '5',
            'updatedTime' => '2026-09-11T12:00:00.000Z',
        ]]],
    ]]);

    expect(fn () => app(YandexMapsPageParser::class)->parseReviews($html, '123'))
        ->toThrow(RuntimeException::class, 'Отзыв имеет некорректный формат.');
});

it('rejects invalid review timestamps', function (string $updatedTime) {
    $html = yandexStateHtml([[
        'type' => 'business',
        'id' => '123',
        'reviewResults' => ['reviews' => [[
            'reviewId' => 'review-123',
            'rating' => 5,
            'updatedTime' => $updatedTime,
        ]]],
    ]]);

    expect(fn () => app(YandexMapsPageParser::class)->parseReviews($html, '123'))
        ->toThrow(RuntimeException::class, 'Отзыв имеет некорректный формат.');
})->with([
    'relative date' => 'tomorrow',
    'invalid calendar date' => '2026-02-30T12:00:00.000Z',
]);
