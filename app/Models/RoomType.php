<?php

namespace App\Models;

use App\Enums\SellUnit;
use Database\Factories\RoomTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * What a property sells: a room or a bed. It has no price; prices live in daily rates.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property string|null $description
 * @property SellUnit $sell_unit
 * @property int $max_adults
 * @property int $max_children
 * @property int $base_occupancy
 * @property bool $smoking_allowed
 * @property bool $pets_allowed
 * @property int|null $terms_document_id
 * @property int $position
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'description', 'sell_unit', 'max_adults', 'max_children', 'base_occupancy',
    'smoking_allowed', 'pets_allowed', 'terms_document_id', 'position', 'archived_at',
])]
class RoomType extends Model implements HasMedia
{
    /** @use HasFactory<RoomTypeFactory> */
    use HasFactory, InteractsWithMedia;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sell_unit' => SellUnit::class,
            'smoking_allowed' => 'boolean',
            'pets_allowed' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * @return BelongsToMany<RatePlan, $this>
     */
    public function ratePlans(): BelongsToMany
    {
        return $this->belongsToMany(RatePlan::class, 'rate_plan_room_types')->withTimestamps();
    }

    /**
     * @param  Builder<RoomType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<RoomType>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos');
    }
}
