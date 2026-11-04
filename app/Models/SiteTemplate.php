<?php

namespace App\Models;

use App\Enums\SiteTemplateStatus;
use App\Enums\SiteTemplateType;
use Carbon\CarbonImmutable;
use Database\Factories\SiteTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A design for the microsite. The code lives in the microsite repo under the same `key`; this row tells estatell who can pick
 * it and which image slots it has. A Custom template belongs to one property.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property SiteTemplateType $type
 * @property int|null $property_id
 * @property SiteTemplateStatus $status
 * @property bool $allows_main_color
 * @property string|null $preview_url
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Property|null $property
 * @property-read Collection<int, SiteTemplateSlot> $slots
 */
#[Fillable(['key', 'name', 'type', 'property_id', 'status', 'allows_main_color', 'preview_url'])]
class SiteTemplate extends Model
{
    /** @use HasFactory<SiteTemplateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SiteTemplateType::class,
            'status' => SiteTemplateStatus::class,
            'allows_main_color' => 'boolean',
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
     * @return HasMany<SiteTemplateSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(SiteTemplateSlot::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<PropertyBookingSite, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(PropertyBookingSite::class);
    }

    /**
     * Available templates this property may pick: every Standard one and its own Custom ones.
     *
     * @param  Builder<SiteTemplate>  $query
     */
    public function scopePickableBy(Builder $query, Property $property): void
    {
        $query->where('status', SiteTemplateStatus::Available)
            ->where(fn (Builder $inner) => $inner->whereNull('property_id')->orWhere('property_id', $property->id));
    }
}
