<?php

declare(strict_types=1);

namespace App\Services;

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

        if (
            ! in_array($scheme, ['http', 'https'], true)
            || ($host !== 'yandex.ru' && ! str_ends_with($host, '.yandex.ru'))
            || preg_match('~/org/(?:[^/]+/)?(\d+)(?:/|$)~', $path, $matches) !== 1
        ) {
            throw new InvalidArgumentException('Некорректная ссылка Яндекс Карт.');
        }

        return $matches[1];
    }

    /**
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
    public function fetch(string $url, int $maxPages = 100): array
    {
        if ($maxPages < 1) {
            throw new InvalidArgumentException('Лимит страниц должен быть больше нуля.');
        }

        $businessId = $this->extractBusinessId($url);
        $firstPageHtml = $this->downloadPage($businessId, 1);

        return [
            'organization' => $this->parseOrganizationHtml($firstPageHtml, $businessId),
            'reviews' => $this->collectReviews(
                $businessId,
                $maxPages,
                $this->parseHtml($firstPageHtml),
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

            if (
                is_array($candidate)
                && isset($candidate[0]['reviewId'])
                && count($candidate) > count($bestCandidate)
            ) {
                $bestCandidate = $candidate;
            }
        }

        if ($bestCandidate === []) {
            throw new RuntimeException(
                'Отзывы не найдены: возможно, Яндекс изменил структуру страницы.',
            );
        }

        return array_values(
            array_map(
                fn (array $review): array => $this->normalizeReview($review),
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
    ): array {
        $reviewsById = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $newReviewsFound = false;

            $pageReviews = $page === 1 && $firstPageReviews !== null
                ? $firstPageReviews
                : $this->fetchPage($businessId, $page);

            foreach ($pageReviews as $review) {
                if (isset($reviewsById[$review['external_id']])) {
                    continue;
                }

                $reviewsById[$review['external_id']] = $review;
                $newReviewsFound = true;
            }

            if (! $newReviewsFound) {
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

        return Http::withUserAgent(self::USER_AGENT)
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

            return [
                'business_id' => $businessId,
                'name' => (string) $item['title'],
                'rating' => (float) $ratingData['ratingValue'],
                'rating_count' => (int) $ratingData['ratingCount'],
                'review_count' => (int) $ratingData['reviewCount'],
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
     * @param  array<string, mixed>  $review
     * @return array{
     *     external_id: string,
     *     author_name: string|null,
     *     text: string|null,
     *     rating: int,
     *     updated_time: string
     * }
     */
    private function normalizeReview(array $review): array
    {
        foreach (['reviewId', 'rating', 'updatedTime'] as $requiredField) {
            if (! array_key_exists($requiredField, $review)) {
                throw new RuntimeException(
                    "В отзыве отсутствует поле {$requiredField}.",
                );
            }
        }

        return [
            'external_id' => (string) $review['reviewId'],
            'author_name' => is_array($review['author'] ?? null)
                && isset($review['author']['name'])
                    ? (string) $review['author']['name']
                    : null,
            'text' => isset($review['text'])
                ? (string) $review['text']
                : null,
            'rating' => (int) $review['rating'],
            'updated_time' => (string) $review['updatedTime'],
        ];
    }
}
