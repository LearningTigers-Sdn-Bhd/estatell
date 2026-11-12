<?php

namespace App\Models;

use App\Enums\TaxWaiverReason;
use Carbon\CarbonImmutable;
use Database\Factories\ReservationUnitTaxWaiverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tax that is not charged for one reservation unit, because someone else collected it or the guest has a
 * receipt. `paid_to` names who collected it and is set only for `PaidElsewhere`. `user_id` is the staff member who
 * marked it. See docs/06-catalogues/03-taxes.md#tax-waivers.
 *
 * @property int $id
 * @property int $property_id
 * @property int $reservation_unit_id
 * @property int $tax_id
 * @property TaxWaiverReason $reason
 * @property string|null $paid_to
 * @property string $reference
 * @property int $user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['tax_id', 'reason', 'paid_to', 'reference'])]
class ReservationUnitTaxWaiver extends Model
{
    /** @use HasFactory<ReservationUnitTaxWaiverFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => TaxWaiverReason::class,
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
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class);
    }

    /**
     * @return BelongsTo<Tax, $this>
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
