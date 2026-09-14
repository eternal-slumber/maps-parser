<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class YandexMapsParser
{
    public const COLLECTION_COMPLETE = 'complete';

    public const COLLECTION_SOURCE_LIMITED = 'source_limited';

    public const COLLECTION_SUSPICIOUS = 'suspicious';

    private const MAX_AVAILABLE_REVIEWS = 600;

    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    public function __construct(
        private YandexMapsUrlResolver $urlResolver,
        private YandexMapsPageParser $pageParser,
    ) {}

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
     *     }>,
     *     collection: array{
     *         status: 'complete'|'source_limited'|'suspicious',
     *         stop_reason: 'reported_total_reached'|'source_limit_reached'|'empty_page'|'repeated_page'|'max_pages_reached'
     *     }
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

        $businessId = $this->urlResolver->resolveBusinessId($url);
        $firstPageHtml = $this->downloadPage($businessId, 1);
        $firstPage = $this->pageParser->parse($firstPageHtml, $businessId);

        $collection = $this->collectReviews(
            $businessId,
            $maxPages,
            $firstPage['reviews'],
            $onPageProcessed,
            $firstPage['organization']['review_count'],
        );

        return [
            'organization' => $firstPage['organization'],
            'reviews' => $collection['reviews'],
            'collection' => [
                'status' => $collection['status'],
                'stop_reason' => $collection['stop_reason'],
            ],
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
        return $this->pageParser->parseOrganization(
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

        $collection = $this->collectReviews($businessId, $maxPages);

        if ($collection['status'] === self::COLLECTION_SUSPICIOUS) {
            throw new RuntimeException(
                "Сбор отзывов подозрительно оборвался: {$collection['stop_reason']}.",
            );
        }

        return $collection['reviews'];
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
        return $this->pageParser->parseReviews(
            $this->downloadPage($businessId, $page),
            $businessId,
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
     * @return array{
     *     reviews: list<array{
     *         external_id: string,
     *         author_name: string|null,
     *         text: string|null,
     *         rating: int,
     *         updated_time: string
     *     }>,
     *     status: 'complete'|'source_limited'|'suspicious',
     *     stop_reason: 'reported_total_reached'|'source_limit_reached'|'empty_page'|'repeated_page'|'max_pages_reached'
     * }
     */
    private function collectReviews(
        string $businessId,
        int $maxPages,
        ?array $firstPageReviews = null,
        ?Closure $onPageProcessed = null,
        ?int $expectedReviewCount = null,
    ): array {
        $reviewsById = [];
        $receivedReviewCount = 0;

        if ($expectedReviewCount === 0) {
            return [
                'reviews' => [],
                'status' => self::COLLECTION_COMPLETE,
                'stop_reason' => 'reported_total_reached',
            ];
        }

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

            if ($pageReviews === []) {
                return [
                    'reviews' => array_values($reviewsById),
                    'status' => self::COLLECTION_SUSPICIOUS,
                    'stop_reason' => 'empty_page',
                ];
            }

            if ($newReviews === []) {
                return [
                    'reviews' => array_values($reviewsById),
                    'status' => self::COLLECTION_SUSPICIOUS,
                    'stop_reason' => 'repeated_page',
                ];
            }

            $receivedReviewCount += count($pageReviews);

            $onPageProcessed?->__invoke(
                $page,
                count($reviewsById),
            );

            if ($expectedReviewCount !== null && count($reviewsById) >= $expectedReviewCount) {
                return [
                    'reviews' => array_values($reviewsById),
                    'status' => self::COLLECTION_COMPLETE,
                    'stop_reason' => 'reported_total_reached',
                ];
            }

            if ($receivedReviewCount >= self::MAX_AVAILABLE_REVIEWS) {
                return [
                    'reviews' => array_values($reviewsById),
                    'status' => self::COLLECTION_SOURCE_LIMITED,
                    'stop_reason' => 'source_limit_reached',
                ];
            }
        }

        return [
            'reviews' => array_values($reviewsById),
            'status' => self::COLLECTION_SUSPICIOUS,
            'stop_reason' => 'max_pages_reached',
        ];
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
}
