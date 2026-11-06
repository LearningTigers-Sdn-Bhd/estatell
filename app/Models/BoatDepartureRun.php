<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The boat and crew of one departure on one date, for the manifest. One row per departure and date.
 *
 * @property int $id
 * @property int $property_id
 * @property int $boat_departure_id
 * @property CarbonImmutable $date
 * @property int|null $boat_id
 * @property string|null $crew
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['boat_id', 'crew'])]
class BoatDepartureRun extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'immutable_date'];
    }

    /** @return BelongsTo<BoatDeparture, $this> */
    public function boatDeparture(): BelongsTo
    {
        return $this->belongsTo(BoatDeparture::class);
    }

    /** @return BelongsTo<Boat, $this> */
    public function boat(): BelongsTo
    {
        return $this->belongsTo(Boat::class);
    }
}
