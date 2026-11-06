<?php

namespace App\Models;

use App\Enums\AmenityType;
use Carbon\CarbonImmutable;
use Database\Factories\AmenityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An item on the shared amenity list (Wi-Fi, pool, balcony). The list is global; platform admins keep it, and
 * properties and room types pick from it. A retired amenity (`active` off) leaves the pickers but stays where it is ticked.
 *
 * @property int $id
 * @property string $name
 * @property AmenityType $type
 * @property int $amenity_category_id
 * @property string|null $icon
 * @property int $position
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AmenityCategory $category
 */
#[Fillable(['name', 'type', 'amenity_category_id', 'icon', 'position', 'active'])]
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
     * @return BelongsTo<AmenityCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AmenityCategory::class, 'amenity_category_id');
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
     * List order: by category, then by position inside it.
     *
     * @param  Builder<Amenity>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy(AmenityCategory::query()->select('position')->whereColumn('amenity_categories.id', 'amenities.amenity_category_id'))
            ->orderBy('amenities.amenity_category_id')
            ->orderBy('amenities.position')
            ->orderBy('amenities.id');
    }
}
