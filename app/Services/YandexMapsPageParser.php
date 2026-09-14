<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

final class YandexMapsPageParser
{
    private const UPDATED_TIME_FORMAT = 'Y-m-d\TH:i:s.v\Z';

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
    public function parse(string $html, string $businessId): array
    {
        $business = $this->business($html, $businessId);
        $organization = $this->normalizeOrganization($business, $businessId);

        return [
            'organization' => $organization,
            'reviews' => $organization['review_count'] === 0
                ? []
                : $this->normalizeReviews($business),
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
    public function parseOrganization(string $html, string $businessId): array
    {
        return $this->normalizeOrganization(
            $this->business($html, $businessId),
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
    public function parseReviews(string $html, string $businessId): array
    {
        return $this->normalizeReviews($this->business($html, $businessId));
    }

    /** @return array<string, mixed> */
    private function business(string $html, string $businessId): array
    {
        $businesses = $this->findBusinesses($this->decode($html), $businessId);

        if ($businesses === []) {
            throw new RuntimeException('Организация не найдена в состоянии страницы.');
        }

        if (count($businesses) > 1) {
            throw new RuntimeException('Найдено несколько организаций с указанным businessId.');
        }

        return $businesses[0];
    }

    /** @return array<mixed> */
    private function decode(string $html): array
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
            throw new RuntimeException('Не удалось декодировать состояние страницы.', 0, $exception);
        }

        if (! is_array($state)) {
            throw new RuntimeException('Состояние страницы имеет некорректный формат.');
        }

        return $state;
    }

    /**
     * @param  array<mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function findBusinesses(array $state, string $businessId): array
    {
        $businesses = [];

        if (
            ($state['type'] ?? null) === 'business'
            && (string) ($state['id'] ?? '') === $businessId
        ) {
            $businesses[] = $state;
        }

        foreach ($state as $value) {
            if (is_array($value)) {
                array_push(
                    $businesses,
                    ...$this->findBusinesses($value, $businessId),
                );
            }
        }

        return $businesses;
    }

    /**
     * @param  array<string, mixed>  $business
     * @return array{
     *     business_id: string,
     *     name: string,
     *     rating: float,
     *     rating_count: int,
     *     review_count: int
     * }
     */
    private function normalizeOrganization(array $business, string $businessId): array
    {
        $ratingData = $business['ratingData'] ?? null;

        if (
            ! array_key_exists('title', $business)
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
            ! is_string($business['title'])
            || trim($business['title']) === ''
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
            'name' => $business['title'],
            'rating' => (float) $rating,
            'rating_count' => $ratingCount,
            'review_count' => $reviewCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $business
     * @return list<array{
     *     external_id: string,
     *     author_name: string|null,
     *     text: string|null,
     *     rating: int,
     *     updated_time: string
     * }>
     */
    private function normalizeReviews(array $business): array
    {
        $candidates = $this->findReviewCandidates($business);

        if ($candidates === []) {
            throw new RuntimeException(
                'Отзывы не найдены: возможно, Яндекс изменил структуру страницы.',
            );
        }

        if (count($candidates) > 1) {
            throw new RuntimeException('Найдено несколько массивов отзывов организации.');
        }

        return array_map(
            fn (mixed $review): array => $this->normalizeReview($review),
            $candidates[0],
        );
    }

    /**
     * @param  array<mixed>  $state
     * @return list<list<mixed>>
     */
    private function findReviewCandidates(array $state): array
    {
        $candidates = [];

        foreach ($state as $key => $value) {
            if (
                $key === 'reviews'
                && is_array($value)
                && ($value === [] || (is_array($value[0] ?? null) && array_key_exists('reviewId', $value[0])))
            ) {
                $candidates[] = array_values($value);
            }

            if (is_array($value)) {
                array_push(
                    $candidates,
                    ...$this->findReviewCandidates($value),
                );
            }
        }

        return $candidates;
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
        $updatedTime = $review['updatedTime'];

        if (
            ! is_string($review['reviewId'])
            || trim($review['reviewId']) === ''
            || ! is_int($review['rating'])
            || $review['rating'] < 1
            || $review['rating'] > 5
            || ! is_string($updatedTime)
            || ! $this->isValidUpdatedTime($updatedTime)
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
            'updated_time' => $updatedTime,
        ];
    }

    private function isValidUpdatedTime(string $updatedTime): bool
    {
        $date = DateTimeImmutable::createFromFormat(
            self::UPDATED_TIME_FORMAT,
            $updatedTime,
            new DateTimeZone('UTC'),
        );

        return $date !== false
            && $date->format(self::UPDATED_TIME_FORMAT) === $updatedTime;
    }
}
