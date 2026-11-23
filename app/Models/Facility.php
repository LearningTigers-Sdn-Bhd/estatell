<?php

namespace App\Models;

use App\Enums\FacilityBookedBy;
use App\Enums\FacilityRateBasis;
use Carbon\CarbonImmutable;
use Database\Factories\FacilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hall, piece of equipment or activity staff can book. Its price, taxes and code are its own charge item.
 * Never deleted; turned off instead. See docs/33-facilities/02-facility-settings.md.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property string|null $description
 * @property FacilityBookedBy $booked_by
 * @property int $capacity How many items or people at one time. Always the limit.
 * @property int $charge_item_id
 * @property FacilityRateBasis $rate_basis
 * @property int $position
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read int|null $sessions_count
 */
#[Fillable(['name', 'description', 'booked_by', 'capacity', 'rate_basis', 'active'])]
class Facility extends Model
{
    /** @use HasFactory<FacilityFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'booked_by' => FacilityBookedBy::class,
            'capacity' => 'integer',
            'rate_basis' => FacilityRateBasis::class,
            'position' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<ChargeItem, $this> */
    public function chargeItem(): BelongsTo
    {
        return $this->belongsTo(ChargeItem::class);
    }

    /** @return HasMany<FacilitySession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(FacilitySession::class);
    }

    /** @param Builder<Facility> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
