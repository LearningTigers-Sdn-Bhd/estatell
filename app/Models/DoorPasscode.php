<?php

namespace App\Models;

use App\Enums\PasscodeKind;
use App\Enums\PasscodeStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DoorPasscodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A PIN on one lock, for one stay or for a person staff named in the label. `code` is stored encrypted because it opens a door.
 *
 * @property int $id
 * @property int $property_id
 * @property int $door_lock_id
 * @property int|null $reservation_unit_id
 * @property PasscodeKind $kind
 * @property PasscodeStatus $status
 * @property string|null $code
 * @property int|null $ttlock_passcode_id
 * @property string $label
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string|null $error
 * @property int|null $issued_by
 * @property CarbonImmutable|null $revoked_at
 * @property string $idempotency_key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['door_lock_id', 'reservation_unit_id', 'kind', 'status', 'code', 'ttlock_passcode_id', 'label', 'starts_at', 'ends_at', 'error', 'issued_by', 'revoked_at', 'idempotency_key'])]
class DoorPasscode extends Model
{
    /** @use HasFactory<DoorPasscodeFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => PasscodeKind::class,
            'status' => PasscodeStatus::class,
            'code' => 'encrypted',
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
