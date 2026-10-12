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
 * and is never stored. It has no `property_id`: the unit scopes it. A posted add-on has a `folio_line_id` and is
 * corrected with `ReverseLine`, not removed.
 *
 * @property int $id
 * @property int $reservation_unit_id
 * @property int $charge_item_id
 * @property int $quantity
 * @property string $unit_amount
 * @property Carbon|null $service_date
 * @property int|null $property_age_group_id The age group of a child price row. Null for the adult price.
 * @property int|null $folio_line_id The charge line that posted this add-on. Null while it is only scheduled.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['charge_item_id', 'quantity', 'unit_amount', 'service_date', 'property_age_group_id'])]
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
     * @return BelongsTo<FolioLine, $this>
     */
    public function folioLine(): BelongsTo
    {
        return $this->belongsTo(FolioLine::class);
    }

    /**
     * @return BelongsTo<ChargeItem, $this>
     */
    public function chargeItem(): BelongsTo
    {
        return $this->belongsTo(ChargeItem::class);
    }

    /**
     * @return BelongsTo<PropertyAgeGroup, $this>
     */
    public function ageGroup(): BelongsTo
    {
        return $this->belongsTo(PropertyAgeGroup::class, 'property_age_group_id');
    }
}
