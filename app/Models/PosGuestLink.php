<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The temporary Loyverse customer that stands for one checked-in unit, so a cashier can attach the room to a sale. It is
 * deleted when the guest checks out. `synced_label` is the name last sent, so an unchanged label sends nothing.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_connection_id
 * @property int $reservation_unit_id
 * @property string $external_customer_id
 * @property string $synced_label
 * @property CarbonImmutable $last_synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['external_customer_id', 'synced_label', 'last_synced_at'])]
class PosGuestLink extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_synced_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<PosConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(PosConnection::class, 'pos_connection_id');
    }

    /**
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class);
    }
}
