<?php

namespace App\Models;

use App\Enums\AmenityType;
use Carbon\CarbonImmutable;
use Database\Factories\AmenityCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group on the shared amenity list (Parking, Bathroom). Global like the list itself; each type has its own categories.
 *
 * @property int $id
 * @property AmenityType $type
 * @property string $name
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['type', 'name', 'position'])]
class AmenityCategory extends Model
{
    /** @use HasFactory<AmenityCategoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AmenityType::class,
            'position' => 'integer',
        ];
    }

    /**
     * @return HasMany<Amenity, $this>
     */
    public function amenities(): HasMany
    {
        return $this->hasMany(Amenity::class);
    }

    /**
     * @param  Builder<AmenityCategory>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
