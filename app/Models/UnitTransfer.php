<?php

namespace App\Models;

use App\Enums\TransferDirection;
use App\Enums\TransferMode;
use Carbon\CarbonImmutable;
use Database\Factories\UnitTransferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How one unit travels in or out by road. One row per unit and direction; the date is the unit's arrival (In) or
 * departure (Out) date. See docs/35-transfers/01-land-transfers.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int $reservation_unit_id
 * @property TransferDirection $direction
 * @property TransferMode $mode
 * @property int $transfer_route_id
 * @property string $pickup_time `H:i:s`
 * @property string|null $flight_number
 * @property int|null $bags
 * @property string|null $note
 * @property int|null $transfer_vehicle_id
 * @property int|null $transfer_driver_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'reservation_unit_id', 'direction', 'mode', 'transfer_route_id', 'pickup_time', 'flight_number', 'bags', 'note', 'transfer_vehicle_id', 'transfer_driver_id'])]
class UnitTransfer extends Model
{
    /** @use HasFactory<UnitTransferFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['direction' => TransferDirection::class, 'mode' => TransferMode::class];
    }

    /** @return BelongsTo<ReservationUnit, $this> */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class);
    }

    /** @return BelongsTo<TransferRoute, $this> */
    public function transferRoute(): BelongsTo
    {
        return $this->belongsTo(TransferRoute::class);
    }

    /** @return BelongsTo<TransferVehicle, $this> */
    public function transferVehicle(): BelongsTo
    {
        return $this->belongsTo(TransferVehicle::class);
    }

    /** @return BelongsTo<TransferDriver, $this> */
    public function transferDriver(): BelongsTo
    {
        return $this->belongsTo(TransferDriver::class);
    }

    /** @return HasMany<UnitTransferAddOn, $this> The charge add-on rows this transfer created. */
    public function addOns(): HasMany
    {
        return $this->hasMany(UnitTransferAddOn::class);
    }
}
