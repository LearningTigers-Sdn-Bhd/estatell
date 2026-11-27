<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The guide and boat of one activity slot: a facility on a date at a time (a session, or the times of a booking with no
 * session). A row exists only while one of the two is set.
 *
 * @property int $id
 * @property int $property_id
 * @property int $facility_id
 * @property CarbonImmutable $slot_date
 * @property string $starts_at
 * @property string $ends_at
 * @property int|null $facility_guide_id
 * @property int|null $facility_boat_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['facility_id', 'slot_date', 'starts_at', 'ends_at', 'facility_guide_id', 'facility_boat_id'])]
class FacilitySlotCrew extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['slot_date' => 'immutable_date'];
    }

    /** @return BelongsTo<Facility, $this> */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /** @return BelongsTo<FacilityGuide, $this> */
    public function guide(): BelongsTo
    {
        return $this->belongsTo(FacilityGuide::class, 'facility_guide_id');
    }

    /** @return BelongsTo<FacilityBoat, $this> */
    public function boat(): BelongsTo
    {
        return $this->belongsTo(FacilityBoat::class, 'facility_boat_id');
    }
}
