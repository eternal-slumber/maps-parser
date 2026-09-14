<?php

declare(strict_types=1);

use App\Services\YandexMapsUrlResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('extracts the business id from an organization link', function (string $url) {
    $businessId = app(YandexMapsUrlResolver::class)->extractBusinessId($url);

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

    $businessId = app(YandexMapsUrlResolver::class)->resolveBusinessId(
        'https://yandex.ru/maps/-/CTtm6ILS',
    );

    expect($businessId)->toBe('137381017899');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD');
});

it('rejects unsupported organization links', function (string $url) {
    expect(fn () => app(YandexMapsUrlResolver::class)->extractBusinessId($url))
        ->toThrow(InvalidArgumentException::class, 'Некорректная ссылка Яндекс Карт.');
})->with([
    'foreign host' => 'https://example.com/maps/org/company/134528915428/',
    'deceptive host' => 'https://yandex.ru.example.com/maps/org/company/134528915428/',
    'non-maps path' => 'https://yandex.ru/search/org/company/134528915428/',
    'missing business id' => 'https://yandex.ru/maps/org/company/',
    'unsupported scheme' => 'ftp://yandex.ru/maps/-/CTtm6ILS',
]);
