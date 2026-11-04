<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BookingHoldLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One room or bed of a hold, as a reservation unit is.
 *
 * @property int $id
 * @property int $booking_hold_id
 * @property int $room_type_id
 * @property int $rate_plan_id
 * @property int $adults
 * @property string $amount The online total of this line.
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['booking_hold_id', 'room_type_id', 'rate_plan_id', 'adults', 'amount', 'position'])]
class BookingHoldLine extends Model
{
    /** @use HasFactory<BookingHoldLineFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<BookingHold, $this>
     */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(BookingHold::class, 'booking_hold_id');
    }

    /**
     * @return HasMany<BookingHoldLineChild, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(BookingHoldLineChild::class);
    }
}
