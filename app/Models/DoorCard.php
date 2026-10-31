<?php

namespace App\Models;

use App\Enums\PasscodeStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DoorCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An IC card registered with TTLock for one lock and, for a guest card, one stay. `card_number` is the card UID read as a
 * big-endian decimal, the number TTLock knows the card by. Uses `PasscodeStatus`: a card goes Pending, Active, then Revoked.
 *
 * @property int $id
 * @property int $property_id
 * @property int $door_lock_id
 * @property int|null $reservation_unit_id
 * @property int $card_number
 * @property int|null $ttlock_card_id
 * @property PasscodeStatus $status
 * @property string $label
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string|null $error
 * @property CarbonImmutable|null $revoked_at
 * @property string $idempotency_key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['door_lock_id', 'reservation_unit_id', 'card_number', 'ttlock_card_id', 'status', 'label', 'starts_at', 'ends_at', 'error', 'revoked_at', 'idempotency_key'])]
class DoorCard extends Model
{
    /** @use HasFactory<DoorCardFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => PasscodeStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<DoorLock, $this>
     */
    public function doorLock(): BelongsTo
    {
        return $this->belongsTo(DoorLock::class);
    }

    /**
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class);
    }
}
