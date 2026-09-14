<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class YandexMapsUrlResolver
{
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    public function extractBusinessId(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)) {
            throw new InvalidArgumentException('Некорректная ссылка Яндекс Карт.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || ! $this->isYandexHost($host)) {
            throw new InvalidArgumentException('Некорректная ссылка Яндекс Карт.');
        }

        if (! str_starts_with($path, '/maps/')) {
            throw new InvalidArgumentException('Некорректная ссылка Яндекс Карт.');
        }

        if (preg_match('~/org/(?:[^/]+/)?(\d+)(?:/|$)~', $path, $matches) === 1) {
            return $matches[1];
        }

        parse_str($parts['query'] ?? '', $query);
        $poiUri = $query['poi']['uri'] ?? null;

        if (is_string($poiUri) && preg_match('~[?&]oid=(\d+)(?:&|$)~', $poiUri, $matches) === 1) {
            return $matches[1];
        }

        throw new InvalidArgumentException('Некорректная ссылка Яндекс Карт.');
    }

    public function resolveBusinessId(string $url): string
    {
        try {
            return $this->extractBusinessId($url);
        } catch (InvalidArgumentException) {
            $parts = parse_url(trim($url));
            $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
            $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
            $path = is_array($parts) ? $parts['path'] ?? '' : '';

            if (
                ! in_array($scheme, ['http', 'https'], true)
                || ! $this->isYandexHost($host)
                || preg_match('~^/maps/-/[A-Za-z0-9_-]+/?$~', $path) !== 1
            ) {
                throw new InvalidArgumentException('Некорректная ссылка Яндекс Карт.');
            }
        }

        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->connectTimeout(5)
                ->timeout(20)
                ->withOptions(['allow_redirects' => false])
                ->head(trim($url))
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException('Не удалось раскрыть сокращённую ссылку Яндекс Карт.', 0, $exception);
        }

        $location = $response->header('Location');

        if ($location === '') {
            throw new InvalidArgumentException('Некорректная ссылка Яндекс Карт.');
        }

        if (str_starts_with($location, '/')) {
            $location = 'https://yandex.ru'.$location;
        }

        return $this->extractBusinessId($location);
    }

    private function isYandexHost(string $host): bool
    {
        return $host === 'yandex.ru' || str_ends_with($host, '.yandex.ru');
    }
}
