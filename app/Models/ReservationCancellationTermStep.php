<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReservationCancellationTermStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cancellation step an online guest was promised, copied from the policy's steps at confirmation.
 *
 * @property int $id
 * @property int $property_id
 * @property int $reservation_cancellation_term_id
 * @property int $days_before
 * @property string|null $amount
 * @property string|null $percent
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'reservation_cancellation_term_id', 'days_before', 'amount', 'percent'])]
class ReservationCancellationTermStep extends Model
{
    /** @use HasFactory<ReservationCancellationTermStepFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'days_before' => 'integer',
            'amount' => 'decimal:2',
            'percent' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ReservationCancellationTerm, $this> */
    public function terms(): BelongsTo
    {
        return $this->belongsTo(ReservationCancellationTerm::class, 'reservation_cancellation_term_id');
    }
}
