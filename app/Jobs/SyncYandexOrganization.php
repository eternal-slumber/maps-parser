<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\Review;
use App\Services\YandexMapsParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class SyncYandexOrganization implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 300;

    public function __construct(public int $organizationId)
    {
        $this->afterCommit = true;
    }

    public function handle(YandexMapsParser $parser): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        if ($this->attempts() === 1) {
            $claimed = Organization::query()
                ->whereKey($organization->id)
                ->where('sync_status', Organization::SYNC_PENDING)
                ->update([
                    'sync_status' => Organization::SYNC_PROCESSING,
                    'processed_pages' => 0,
                    'processed_reviews' => 0,
                    'sync_error' => null,
                ]) === 1;

            if (! $claimed) {
                return;
            }

            $organization->refresh();
        } elseif ($organization->sync_status !== Organization::SYNC_PROCESSING) {
            return;
        }

        try {
            $result = $parser->fetch(
                url: $organization->source_url,
                maxPages: 20,
                onPageProcessed: function (
                    int $page,
                    int $processedReviews,
                ) use ($organization): void {
                    $organization->update([
                        'processed_pages' => $page,
                        'processed_reviews' => $processedReviews,
                    ]);
                },
            );

            if ($result['collection']['status'] === YandexMapsParser::COLLECTION_SUSPICIOUS) {
                $reason = match ($result['collection']['stop_reason']) {
                    'empty_page' => 'Яндекс вернул пустую страницу до ожидаемого конца.',
                    'repeated_page' => 'Яндекс повторил уже полученную страницу отзывов.',
                    'max_pages_reached' => 'Достигнут лимит страниц до полного результата.',
                    default => 'Причина обрыва не определена.',
                };

                throw new RuntimeException("Сбор отзывов подозрительно оборвался: {$reason}");
            }
        } catch (Throwable $exception) {
            Log::warning('Сбой синхронизации отзывов Яндекс Карт.', [
                'business_id' => $organization->business_id,
                'page' => $organization->processed_pages + 1,
                'reason' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        DB::transaction(function () use ($result): void {
            $organization = Organization::query()
                ->lockForUpdate()
                ->find($this->organizationId);

            if ($organization === null) {
                return;
            }

            $this->upsertReviews($organization, $result['reviews']);

            $organization->update([
                'business_id' => $result['organization']['business_id'],
                'name' => $result['organization']['name'],
                'rating' => $result['organization']['rating'],
                'rating_count' => $result['organization']['rating_count'],
                'review_count' => $result['organization']['review_count'],
                'sync_status' => $result['collection']['status'] === YandexMapsParser::COLLECTION_SOURCE_LIMITED
                    ? Organization::SYNC_LIMITED
                    : Organization::SYNC_COMPLETED,
                'sync_error' => null,
                'last_synced_at' => now(),
            ]);
        });
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new FailOnException([UnexpectedValueException::class])];
    }

    public function failed(?Throwable $exception): void
    {
        Organization::query()
            ->whereKey($this->organizationId)
            ->update([
                'sync_status' => Organization::SYNC_FAILED,
                'sync_error' => Str::limit(
                    $exception?->getMessage() ?? 'Синхронизация завершилась с ошибкой.',
                    2000,
                ),
            ]);
    }

    /**
     * @param  list<array{
     *     external_id: string,
     *     author_name: string|null,
     *     text: string|null,
     *     rating: int,
     *     updated_time: string
     * }>  $reviews
     */
    private function upsertReviews(Organization $organization, array $reviews): void
    {
        $seenAt = now();

        $organization->reviews()->update(['is_active' => false]);

        $rows = array_map(
            fn (array $review): array => [
                'organization_id' => $organization->id,
                'external_id' => $review['external_id'],
                'author_name' => $review['author_name'],
                'text' => $review['text'],
                'rating' => $review['rating'],
                'published_at' => Carbon::parse($review['updated_time'])->utc(),
                'is_active' => true,
                'last_seen_at' => $seenAt,
            ],
            $reviews,
        );

        Review::query()->upsert(
            $rows,
            ['organization_id', 'external_id'],
            ['author_name', 'text', 'rating', 'published_at', 'is_active', 'last_seen_at'],
        );
    }
}
