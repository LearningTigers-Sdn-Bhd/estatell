<?php

namespace App\Models;

use App\Enums\NearbyPlaceStatus;
use Carbon\CarbonImmutable;
use Database\Factories\NearbyPlaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A place near properties (a jetty, a market, a beach), shared across properties and found by its fingerprint.
 * Platform admins review it; owners and guests never see the status.
 *
 * @property int $id
 * @property string $name
 * @property numeric-string $latitude
 * @property numeric-string $longitude
 * @property string $google_maps_url
 * @property string $fingerprint
 * @property NearbyPlaceStatus $status
 * @property int|null $created_by_property_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'latitude', 'longitude', 'google_maps_url', 'fingerprint', 'status', 'created_by_property_id'])]
class NearbyPlace extends Model
{
    /** @use HasFactory<NearbyPlaceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NearbyPlaceStatus::class,
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function createdByProperty(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'created_by_property_id');
    }

    /** @return BelongsToMany<Property, $this> */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'property_nearby_places')->withPivot(['description', 'position'])->withTimestamps();
    }

    /**
     * @param  Builder<NearbyPlace>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', NearbyPlaceStatus::Approved->value);
    }
}
