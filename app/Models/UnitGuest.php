<?php

namespace App\Models;

use Database\Factories\UnitGuestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An occupant of a unit. A guest appears once per unit and one occupant is primary. It has no timestamps.
 *
 * @property int $id
 * @property int $reservation_unit_id
 * @property int $guest_id
 * @property bool $is_primary
 */
#[Fillable(['guest_id', 'is_primary'])]
class UnitGuest extends Model
{
    /** @use HasFactory<UnitGuestFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
