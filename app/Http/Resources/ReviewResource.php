<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Review */
final class ReviewResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     external_id: string,
     *     author_name: string|null,
     *     text: string|null,
     *     rating: int,
     *     published_at: string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'author_name' => $this->author_name,
            'text' => $this->text,
            'rating' => $this->rating,
            'published_at' => $this->published_at->toISOString(),
        ];
    }
}
