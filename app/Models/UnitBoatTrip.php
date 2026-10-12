<?php

namespace App\Models;

use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use Database\Factories\UnitBoatTripFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How one unit goes in or out by boat. One row per unit and direction. A scheduled trip points at a departure.
 *
 * @property int $id
 * @property int $property_id
 * @property int $reservation_unit_id
 * @property BoatDirection $direction
 * @property BoatMode $mode
 * @property int|null $boat_departure_id
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['property_id', 'reservation_unit_id', 'direction', 'mode', 'boat_departure_id', 'note'])]
class UnitBoatTrip extends Model
{
    /** @use HasFactory<UnitBoatTripFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => BoatDirection::class,
            'mode' => BoatMode::class,
        ];
    }

    /** @return BelongsTo<ReservationUnit, $this> */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class);
    }

    /** @return BelongsTo<BoatDeparture, $this> */
    public function boatDeparture(): BelongsTo
    {
        return $this->belongsTo(BoatDeparture::class);
    }
}
