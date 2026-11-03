<?php

namespace App\Models;

use App\Enums\AmenityType;
use Carbon\CarbonImmutable;
use Database\Factories\AmenityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An item on the shared amenity list (Wi-Fi, pool, balcony). The list is global; platform admins keep it, and
 * properties and room types pick from it. A retired amenity (`active` off) leaves the pickers but stays where it is ticked.
 *
 * @property int $id
 * @property string $name
 * @property AmenityType $type
 * @property string $category
 * @property string|null $icon
 * @property int $position
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'type', 'category', 'icon', 'position', 'active'])]
class Amenity extends Model
{
    /** @use HasFactory<AmenityFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AmenityType::class,
            'position' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Amenity>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * @param  Builder<Amenity>  $query
     */
    public function scopeOfType(Builder $query, AmenityType $type): void
    {
        $query->where('type', $type->value);
    }

    /**
     * @param  Builder<Amenity>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
