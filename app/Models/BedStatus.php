<?php

namespace App\Models;

use App\Enums\HousekeepingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The housekeeping status of one dorm bed. Created with the bed; written by `UpdateBedStatus`, `UpdateRoomStatus` (a
 * whole dorm), check-out and the night audit, never mass assigned. See docs/19-housekeeping/04-dorm-beds.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int $bed_id
 * @property HousekeepingStatus $status
 * @property int|null $last_changed_by
 * @property CarbonImmutable|null $last_changed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class BedStatus extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => HousekeepingStatus::class,
            'last_changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Bed, $this> */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }
}
