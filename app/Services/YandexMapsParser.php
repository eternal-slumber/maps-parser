<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class YandexMapsParser
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

    /**
     * @param  (Closure(int, int): void)|null  $onPageProcessed
     * @return array{
     *     organization: array{
     *         business_id: string,
     *         name: string,
     *         rating: float,
     *         rating_count: int,
     *         review_count: int
     *     },
     *     reviews: list<array{
     *         external_id: string,
     *         author_name: string|null,
     *         text: string|null,
     *         rating: int,
     *         updated_time: string
     *     }>
     * }
     */
    public function fetch(
        string $url,
        int $maxPages = 100,
        ?Closure $onPageProcessed = null,
    ): array {
        if ($maxPages < 1) {
            throw new InvalidArgumentException('Лимит страниц должен быть больше нуля.');
        }

        $businessId = $this->resolveBusinessId($url);
        $firstPageHtml = $this->downloadPage($businessId, 1);
        $organization = $this->parseOrganizationHtml($firstPageHtml, $businessId);
        $firstPageReviews = $organization['review_count'] === 0
            ? []
            : $this->parseHtml($firstPageHtml);

        return [
            'organization' => $organization,
            'reviews' => $this->collectReviews(
                $businessId,
                $maxPages,
                $firstPageReviews,
                $onPageProcessed,
                $organization['review_count'],
            ),
        ];
    }

    /**
     * @return array{
     *     business_id: string,
     *     name: string,
     *     rating: float,
     *     rating_count: int,
     *     review_count: int
     * }
     */
    public function fetchOrganization(string $businessId): array
    {
        return $this->parseOrganizationHtml(
            $this->downloadPage($businessId, 1),
            $businessId,
        );
    }

    /**
     * @return list<array{
     *     external_id: string,
     *     author_name: string|null,
     *     text: string|null,
     *     rating: int,
     *     updated_time: string
     * }>
     */
    public function fetchAll(string $businessId, int $maxPages = 100): array
    {
        if ($maxPages < 1) {
            throw new InvalidArgumentException('Лимит страниц должен быть больше нуля.');
        }

        return $this->collectReviews($businessId, $maxPages);
    }

    /**
     * @return list<array{
     *     external_id: string,
     *     author_name: string|null,
     *     text: string|null,
     *     rating: int,
     *     updated_time: string
     * }>
     */
    public function fetchPage(string $businessId, int $page = 1): array
    {
        return $this->parseHtml($this->downloadPage($businessId, $page));
    }

    /**
     * @return list<array{
     *     external_id: string,
     *     author_name: string|null,
     *     text: string|null,
     *     rating: int,
     *     updated_time: string
     * }>
     */
    public function parseHtml(string $html): array
    {
        preg_match_all(
            '/"reviews"\s*:\s*\[/',
            $html,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $bestCandidate = [];
        $foundEmptyReviews = false;

        foreach ($matches[0] as [, $offset]) {
            $arrayStart = strpos($html, '[', (int) $offset);

            if ($arrayStart === false) {
                continue;
            }

            $json = $this->readJsonArray($html, $arrayStart);

            if ($json === null) {
                continue;
            }

            try {
                $candidate = json_decode(
                    $json,
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (JsonException) {
                continue;
            }

            if (! is_array($candidate)) {
                continue;
            }

            if ($candidate === []) {
                $foundEmptyReviews = true;

                continue;
            }

            if (isset($candidate[0]['reviewId']) && count($candidate) > count($bestCandidate)) {
                $bestCandidate = $candidate;
            }
        }

        if ($bestCandidate === []) {
            if ($foundEmptyReviews) {
                return [];
            }

            throw new RuntimeException(
                'Отзывы не найдены: возможно, Яндекс изменил структуру страницы.',
            );
        }

        return array_values(
            array_map(
                fn (mixed $review): array => $this->normalizeReview($review),
                $bestCandidate,
            ),
        );
    }

    /**
     * @param  list<array{
     *     external_id: string,
     *     author_name: string|null,
     *     text: string|null,
     *     rating: int,
     *     updated_time: string
     * }>|null  $firstPageReviews
     * @param  (Closure(int, int): void)|null  $onPageProcessed
     * @return list<array{
     *     external_id: string,
     *     author_name: string|null,
     *     text: string|null,
     *     rating: int,
     *     updated_time: string
     * }>
     */
    private function collectReviews(
        string $businessId,
        int $maxPages,
        ?array $firstPageReviews = null,
        ?Closure $onPageProcessed = null,
        ?int $expectedReviewCount = null,
    ): array {
        $reviewsById = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $newReviews = [];

            $pageReviews = $page === 1 && $firstPageReviews !== null
                ? $firstPageReviews
                : $this->fetchPage($businessId, $page);

            foreach ($pageReviews as $review) {
                if (isset($reviewsById[$review['external_id']])) {
                    continue;
                }

                $reviewsById[$review['external_id']] = $review;
                $newReviews[] = $review;
            }

            if ($newReviews === []) {
                break;
            }

            $onPageProcessed?->__invoke(
                $page,
                count($reviewsById),
            );

            if ($expectedReviewCount !== null && count($reviewsById) >= $expectedReviewCount) {
                break;
            }
        }

        return array_values($reviewsById);
    }

    private function downloadPage(string $businessId, int $page): string
    {
        if ($businessId === '' || ! ctype_digit($businessId)) {
            throw new InvalidArgumentException('Некорректный businessId.');
        }

        if ($page < 1) {
            throw new InvalidArgumentException('Номер страницы должен быть больше нуля.');
        }

        $html = Http::withUserAgent(self::USER_AGENT)
            ->withHeaders([
                'Accept' => 'text/html',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
            ])
            ->connectTimeout(5)
            ->timeout(20)
            ->get(
                "https://yandex.ru/maps/org/{$businessId}/reviews/",
                ['page' => $page],
            )
            ->throw()
            ->body();

        $this->ensurePageIsUsable($html);

        return $html;
    }

    private function isYandexHost(string $host): bool
    {
        return $host === 'yandex.ru' || str_ends_with($host, '.yandex.ru');
    }

    private function ensurePageIsUsable(string $html): void
    {
        if (trim($html) === '') {
            throw new RuntimeException('Яндекс вернул пустую страницу.');
        }

        foreach ([
            '/checkcaptcha',
            'CheckboxCaptcha',
            'SmartCaptcha',
            'Подтвердите, что запросы отправляли вы',
        ] as $captchaMarker) {
            if (str_contains($html, $captchaMarker)) {
                throw new RuntimeException('Яндекс заблокировал запрос или запросил captcha.');
            }
        }
    }

    /**
     * @return array{
     *     business_id: string,
     *     name: string,
     *     rating: float,
     *     rating_count: int,
     *     review_count: int
     * }
     */
    private function parseOrganizationHtml(string $html, string $businessId): array
    {
        if (preg_match(
            '~<script\b[^>]*\bclass=(["\'])[^"\']*\bstate-view\b[^"\']*\1[^>]*>(.*?)</script>~s',
            $html,
            $matches,
        ) !== 1) {
            throw new RuntimeException('Состояние страницы организации не найдено.');
        }

        try {
            $state = json_decode($matches[2], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Не удалось декодировать состояние страницы организации.', 0, $exception);
        }

        $items = is_array($state)
            ? $state['stack'][0]['results']['items'] ?? null
            : null;

        if (! is_array($items)) {
            throw new RuntimeException('Список организаций не найден в состоянии страницы.');
        }

        foreach ($items as $item) {
            if (
                ! is_array($item)
                || ($item['type'] ?? null) !== 'business'
                || (string) ($item['id'] ?? '') !== $businessId
            ) {
                continue;
            }

            $ratingData = $item['ratingData'] ?? null;

            if (
                ! array_key_exists('title', $item)
                || ! is_array($ratingData)
                || ! array_key_exists('ratingValue', $ratingData)
                || ! array_key_exists('ratingCount', $ratingData)
                || ! array_key_exists('reviewCount', $ratingData)
            ) {
                throw new RuntimeException('Данные организации неполные.');
            }

            $rating = $ratingData['ratingValue'];
            $ratingCount = $ratingData['ratingCount'];
            $reviewCount = $ratingData['reviewCount'];

            if (
                ! is_string($item['title'])
                || trim($item['title']) === ''
                || (! is_int($rating) && ! is_float($rating))
                || $rating < 0
                || $rating > 5
                || ! is_int($ratingCount)
                || $ratingCount < 0
                || ! is_int($reviewCount)
                || $reviewCount < 0
            ) {
                throw new RuntimeException('Данные организации имеют некорректный формат.');
            }

            return [
                'business_id' => $businessId,
                'name' => $item['title'],
                'rating' => (float) $rating,
                'rating_count' => $ratingCount,
                'review_count' => $reviewCount,
            ];
        }

        throw new RuntimeException('Организация не найдена в состоянии страницы.');
    }

    private function readJsonArray(string $source, int $start): ?string
    {
        $depth = 0;
        $insideString = false;
        $escaped = false;
        $length = strlen($source);

        for ($index = $start; $index < $length; $index++) {
            $character = $source[$index];

            if ($insideString) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($character === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($character === '"') {
                    $insideString = false;
                }

                continue;
            }

            if ($character === '"') {
                $insideString = true;

                continue;
            }

            if ($character === '[') {
                $depth++;

                continue;
            }

            if ($character === ']') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $start, $index - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @return array{
     *     external_id: string,
     *     author_name: string|null,
     *     text: string|null,
     *     rating: int,
     *     updated_time: string
     * }
     */
    private function normalizeReview(mixed $review): array
    {
        if (! is_array($review)) {
            throw new RuntimeException('Отзыв имеет некорректный формат.');
        }

        foreach (['reviewId', 'rating', 'updatedTime'] as $requiredField) {
            if (! array_key_exists($requiredField, $review)) {
                throw new RuntimeException(
                    "В отзыве отсутствует поле {$requiredField}.",
                );
            }
        }

        $author = $review['author'] ?? null;

        if (
            ! is_string($review['reviewId'])
            || trim($review['reviewId']) === ''
            || ! is_int($review['rating'])
            || $review['rating'] < 1
            || $review['rating'] > 5
            || ! is_string($review['updatedTime'])
            || trim($review['updatedTime']) === ''
            || (isset($review['text']) && ! is_string($review['text']))
            || ($author !== null && ! is_array($author))
            || (is_array($author) && isset($author['name']) && ! is_string($author['name']))
        ) {
            throw new RuntimeException('Отзыв имеет некорректный формат.');
        }

        return [
            'external_id' => $review['reviewId'],
            'author_name' => is_array($author) && isset($author['name'])
                ? $author['name']
                : null,
            'text' => $review['text'] ?? null,
            'rating' => $review['rating'],
            'updated_time' => $review['updatedTime'],
        ];
    }
}
