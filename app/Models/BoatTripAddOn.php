<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BoatTripAddOnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a boat trip to the fare add-on rows it created. A fare with child prices writes several rows, so the link is a
 * table. Removing the add-on or the trip removes the link. It has no `property_id`: the trip and the add-on scope it.
 *
 * @property int $id
 * @property int $unit_boat_trip_id
 * @property int $unit_add_on_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['unit_boat_trip_id', 'unit_add_on_id'])]
class BoatTripAddOn extends Model
{
    /** @use HasFactory<BoatTripAddOnFactory> */
    use HasFactory;

    /** @return BelongsTo<UnitBoatTrip, $this> */
    public function unitBoatTrip(): BelongsTo
    {
        return $this->belongsTo(UnitBoatTrip::class);
    }

    /** @return BelongsTo<UnitAddOn, $this> */
    public function unitAddOn(): BelongsTo
    {
        return $this->belongsTo(UnitAddOn::class);
    }
}
