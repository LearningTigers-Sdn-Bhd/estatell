<?php

namespace App\Models;

use App\Enums\BoatDirection;
use Carbon\CarbonImmutable;
use Database\Factories\BoatDepartureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One boat that runs every day at the same time, in one direction.
 *
 * @property int $id
 * @property int $property_id
 * @property BoatDirection $direction
 * @property string $leaves_at Time of day, HH:MM:SS.
 * @property string $arrives_at Time of day, HH:MM:SS.
 * @property string|null $pickup_point
 * @property int|null $seats
 * @property int|null $charge_item_id The fare.
 * @property int|null $default_boat_id Default boat from settings.
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read int $departures Set by a selectRaw alias of count(*) in BoatFareUsage.
 */
#[Fillable(['direction', 'leaves_at', 'arrives_at', 'pickup_point', 'seats', 'charge_item_id', 'default_boat_id', 'active'])]
class BoatDeparture extends Model
{
    /** @use HasFactory<BoatDepartureFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => BoatDirection::class,
            'seats' => 'integer',
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

    /** @return BelongsTo<Boat, $this> */
    public function defaultBoat(): BelongsTo
    {
        return $this->belongsTo(Boat::class, 'default_boat_id');
    }

    /**
     * The default crew: one member is in charge (`pivot->in_charge`), the rest are assistants.
     *
     * @return BelongsToMany<BoatCrewMember, $this>
     */
    public function crew(): BelongsToMany
    {
        return $this->belongsToMany(BoatCrewMember::class, 'boat_departure_crew', 'boat_departure_id', 'boat_crew_member_id')
            ->withPivot('in_charge')
            ->withTimestamps()
            ->orderByDesc('boat_departure_crew.in_charge')
            ->orderBy('boat_crew_members.name');
    }

    /** @param Builder<BoatDeparture> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('leaves_at')->orderBy('id');
    }
}
