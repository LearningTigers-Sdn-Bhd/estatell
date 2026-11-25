<?php

namespace App\Models;

use App\Enums\PosQrSessionKind;
use Carbon\CarbonImmutable;
use Database\Factories\PosQrSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A revocable QR token. A table session lets a table order from its phone until it is settled or expires. A room session
 * lets an in-house guest order to the room, behind the stay PIN and the primary guest's name.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $pos_outlet_id
 * @property int|null $pos_table_id
 * @property int|null $reservation_unit_id
 * @property PosQrSessionKind $kind
 * @property string $token
 * @property string|null $stay_pin_hash
 * @property bool $single_use
 * @property int $failed_pin_attempts
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['pos_outlet_id', 'pos_table_id', 'reservation_unit_id', 'kind', 'token', 'stay_pin_hash', 'single_use', 'failed_pin_attempts', 'locked_until', 'expires_at', 'revoked_at', 'created_by_user_id'])]
class PosQrSession extends Model
{
    /** @use HasFactory<PosQrSessionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PosQrSessionKind::class,
            'single_use' => 'boolean',
            'failed_pin_attempts' => 'integer',
            'locked_until' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * @return BelongsTo<PosOutlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }

    /**
     * @return BelongsTo<PosTable, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(PosTable::class, 'pos_table_id');
    }

    /**
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class, 'reservation_unit_id');
    }
}
