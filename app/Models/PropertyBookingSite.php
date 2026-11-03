<?php

namespace App\Models;

use App\Enums\BookingEngineMode;
use Carbon\CarbonImmutable;
use Database\Factories\PropertyBookingSiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 */
#[Fillable([
    'property_id', 'mode', 'site_template_id', 'main_color', 'about_text', 'seo_title', 'seo_description', 'published_at',
])]
class PropertyBookingSite extends Model
{
    /** @use HasFactory<PropertyBookingSiteFactory> */
    use HasFactory;

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
}
