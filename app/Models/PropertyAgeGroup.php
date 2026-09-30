<?php

namespace App\Models;

use Database\Factories\PropertyAgeGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One property-defined age band, such as Infant 0-3 or Child 4-11.
 *
 * @property int $id
 * @property int $property_id
 * @property string $label
 * @property int $min_age
 * @property int $max_age
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['label', 'min_age', 'max_age'])]
class PropertyAgeGroup extends Model
{
    /** @use HasFactory<PropertyAgeGroupFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'min_age' => 'integer',
            'max_age' => 'integer',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @param Builder<PropertyAgeGroup> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('min_age');
    }
}
