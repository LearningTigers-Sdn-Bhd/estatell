<?php

namespace App\Models;

use Database\Factories\ReservationUnitChildFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The age of one child on a unit. Re-pricing reads these rows. It has no timestamps.
 *
 * @property int $id
 * @property int $reservation_unit_id
 * @property int $age
 */
#[Fillable(['age'])]
class ReservationUnitChild extends Model
{
    /** @use HasFactory<ReservationUnitChildFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class);
    }
}
