<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $source_url
 * @property string $business_id
 * @property string|null $name
 * @property float $rating
 * @property int $rating_count
 * @property int $review_count
 * @property string $sync_status
 * @property int $processed_pages
 * @property int $processed_reviews
 * @property string|null $sync_error
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, Review> $reviews
 */
#[Fillable([
    'user_id',
    'source_url',
    'business_id',
    'name',
    'rating',
    'rating_count',
    'review_count',
    'sync_status',
    'processed_pages',
    'processed_reviews',
    'sync_error',
    'last_synced_at',
])]
class Organization extends Model
{
    public const SYNC_PENDING = 'pending';

    public const SYNC_PROCESSING = 'processing';

    public const SYNC_COMPLETED = 'completed';

    public const SYNC_LIMITED = 'limited';

    public const SYNC_FAILED = 'failed';

    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'rating_count' => 'integer',
            'review_count' => 'integer',
            'processed_pages' => 'integer',
            'processed_reviews' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }
}
