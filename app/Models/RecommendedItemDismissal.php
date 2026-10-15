<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RecommendedItemDismissalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "Not needed" answer to one item of the Recommended next checklist. RecommendedChecklist reads it.
 *
 * @property int $id
 * @property int $property_id
 * @property string $item
 * @property int|null $dismissed_by
 * @property CarbonImmutable $dismissed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'item', 'dismissed_by', 'dismissed_at'])]
class RecommendedItemDismissal extends Model
{
    /** @use HasFactory<RecommendedItemDismissalFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dismissed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<User, $this> */
    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }
}
