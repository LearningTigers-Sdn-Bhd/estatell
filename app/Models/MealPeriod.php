<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MealPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One property-defined window in which a meal is served.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property string $starts_at Time of day, HH:MM:SS.
 * @property string $ends_at Time of day, HH:MM:SS.
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'starts_at', 'ends_at', 'active'])]
class MealPeriod extends Model
{
    /** @use HasFactory<MealPeriodFactory> */
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

    /** @param Builder<MealPeriod> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** @param Builder<MealPeriod> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('starts_at')->orderBy('id');
    }
}
