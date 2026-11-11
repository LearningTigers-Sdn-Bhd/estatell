<?php

namespace App\Models;

use App\Enums\BookingEngineMode;
use Carbon\CarbonImmutable;
use Database\Factories\PropertyBookingSiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The booking website settings of one property: its mode, look, texts and whether it is published.
 *
 * @property int $id
 * @property int $property_id
 * @property BookingEngineMode $mode
 * @property int|null $site_template_id
 * @property string|null $main_color
 * @property string|null $about_text
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Property $property
 * @property-read SiteTemplate|null $siteTemplate
 */
#[Fillable([
    'property_id', 'mode', 'site_template_id', 'main_color', 'about_text', 'seo_title', 'seo_description', 'published_at',
])]
class PropertyBookingSite extends Model implements HasMedia
{
    /** @use HasFactory<PropertyBookingSiteFactory> */
    use HasFactory, InteractsWithMedia;

    /** The media collection of the template images. Each file carries its slot key as the `slot` custom property. */
    public const string IMAGES = 'images';

    public const int IMAGE_MAX_KB = 5120;

    public const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public const array IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => BookingEngineMode::class,
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * @return BelongsTo<SiteTemplate, $this>
     */
    public function siteTemplate(): BelongsTo
    {
        return $this->belongsTo(SiteTemplate::class);
    }

    /**
     * Template images are shown to guests, so they live on the public media disk.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::IMAGES)
            ->acceptsMimeTypes(self::IMAGE_MIME_TYPES)
            ->useDisk(config('filesystems.public_media'));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->performOnCollections(self::IMAGES)->nonQueued()->width(400);
        $this->addMediaConversion('web')->performOnCollections(self::IMAGES)->nonQueued()->width(1600);
    }
}
