<?php

namespace App\Models;

use App\Enums\PolicyChargeMode;
use App\Enums\PolicyRateBasis;
use App\Enums\PolicyScope;
use Carbon\CarbonImmutable;
use Database\Factories\ReservationCancellationTermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cancellation terms an online guest was promised, copied from the `CANCEL` policy when the booking is confirmed.
 * A later change to the policy does not change them. The tax on the fee is not kept: it follows the live settings.
 *
 * @property int $id
 * @property int $property_id
 * @property int $reservation_id
 * @property PolicyChargeMode $charge_mode
 * @property string|null $amount
 * @property string|null $percent
 * @property PolicyRateBasis|null $percent_basis
 * @property PolicyScope $applies_to
 * @property int|null $free_until_days
 * @property string $text_en
 * @property string $text_ms
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'property_id', 'reservation_id', 'charge_mode', 'amount', 'percent', 'percent_basis', 'applies_to',
    'free_until_days', 'text_en', 'text_ms',
])]
class ReservationCancellationTerm extends Model
{
    /** @use HasFactory<ReservationCancellationTermFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'charge_mode' => PolicyChargeMode::class,
            'amount' => 'decimal:2',
            'percent' => 'decimal:2',
            'percent_basis' => PolicyRateBasis::class,
            'applies_to' => PolicyScope::class,
            'free_until_days' => 'integer',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
