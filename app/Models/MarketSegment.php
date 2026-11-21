<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MarketSegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A market segment the property sorts its bookings by (Leisure, Corporate, Groups...). Turned off, never deleted.
 * See docs/12-reservations/15-market-segments.md.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'active'])]
class MarketSegment extends Model
{
    /** @use HasFactory<MarketSegmentFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @param Builder<MarketSegment> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
