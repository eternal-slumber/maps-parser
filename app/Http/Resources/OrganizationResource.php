<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Organization */
final class OrganizationResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     url: string,
     *     business_id: string,
     *     name: string|null,
     *     rating: float,
     *     rating_count: int,
     *     review_count: int,
     *     sync_status: string,
     *     processed_pages: int,
     *     processed_reviews: int,
     *     sync_error: string|null,
     *     last_synced_at: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->source_url,
            'business_id' => $this->business_id,
            'name' => $this->name,
            'rating' => $this->rating,
            'rating_count' => $this->rating_count,
            'review_count' => $this->review_count,
            'sync_status' => $this->sync_status,
            'processed_pages' => $this->processed_pages,
            'processed_reviews' => $this->processed_reviews,
            'sync_error' => $this->sync_error,
            'last_synced_at' => $this->last_synced_at?->toISOString(),
        ];
    }
}
