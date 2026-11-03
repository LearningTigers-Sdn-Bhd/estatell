<?php

namespace App\Models;

use App\Enums\SellUnit;
use Carbon\CarbonImmutable;
use Database\Factories\RoomTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * What a property sells: a room or a bed. It has no price; prices live in daily rates.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property string|null $description
 * @property SellUnit $sell_unit
 * @property string|null $print_label The short label printed on the House status report (KING, SKING). Null: the initials of the name.
 * @property int $max_adults
 * @property int $max_children
 * @property int $base_occupancy
 * @property bool $smoking_allowed
 * @property bool $pets_allowed
 * @property int|null $terms_document_id
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read bool $rooms_exists Set by withExists('rooms').
 */
#[Fillable([
    'name', 'print_label', 'description', 'sell_unit', 'max_adults', 'max_children', 'base_occupancy',
    'smoking_allowed', 'pets_allowed', 'terms_document_id', 'position', 'archived_at',
])]
class RoomType extends Model implements HasMedia
{
    /** @use HasFactory<RoomTypeFactory> */
    use HasFactory, InteractsWithMedia;

    public const string PHOTOS = 'photos';

    public const int PHOTO_LIMIT = 10;

    public const int PHOTO_MAX_KB = 5120;

    public const array PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public const array PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

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
     * @return BelongsTo<TermsDocument, $this>
     */
    public function termsDocument(): BelongsTo
    {
        return $this->belongsTo(TermsDocument::class);
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
     * @return BelongsToMany<Amenity, $this>
     */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'room_type_amenities');
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

    /**
     * Photos are shown to guests, so they live on the public media disk.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PHOTOS)
            ->acceptsMimeTypes(self::PHOTO_MIME_TYPES)
            ->useDisk(config('filesystems.public_media'));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(400)->performOnCollections(self::PHOTOS)->nonQueued();
        $this->addMediaConversion('web')->width(1600)->performOnCollections(self::PHOTOS)->nonQueued();
    }
}
