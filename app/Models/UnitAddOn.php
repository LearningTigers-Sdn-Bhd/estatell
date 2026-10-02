<?php

namespace App\Models;

use Database\Factories\UnitAddOnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An extra sold with one unit. Nightly charge items have one row per night. The total is `quantity × unit_amount`
 * and is never stored. It has no `property_id`: the unit scopes it.
 *
 * @property int $id
 * @property int $reservation_unit_id
 * @property int $charge_item_id
 * @property int $quantity
 * @property string $unit_amount
 * @property Carbon|null $service_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['charge_item_id', 'quantity', 'unit_amount', 'service_date'])]
class UnitAddOn extends Model
{
    /** @use HasFactory<UnitAddOnFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount' => 'decimal:2',
            'service_date' => 'date',
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
     * @return BelongsTo<ChargeItem, $this>
     */
    public function chargeItem(): BelongsTo
    {
        return $this->belongsTo(ChargeItem::class);
    }
}
