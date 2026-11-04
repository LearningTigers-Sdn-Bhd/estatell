<?php

namespace App\Models;

use App\Enums\BookingHoldStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BookingHoldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rooms kept for a guest while they pay online. It is not a booking: the guest record and reservation come only after
 * payment. Guest details are encrypted, and emptied by a purge. See docs/29-booking-engine/03-checkout-and-hold.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int $booking_client_id
 * @property string $token
 * @property BookingHoldStatus $status
 * @property CarbonImmutable $arrival_date
 * @property CarbonImmutable $departure_date
 * @property string $total_amount The online total.
 * @property CarbonImmutable $expires_at
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property string|null $guest_nationality
 * @property string|null $special_requests
 * @property string|null $guest_ip_hash
 * @property int|null $reservation_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'property_id', 'booking_client_id', 'token', 'status', 'arrival_date', 'departure_date', 'total_amount', 'expires_at',
    'guest_name', 'guest_email', 'guest_phone', 'guest_nationality', 'special_requests', 'guest_ip_hash', 'reservation_id',
])]
class BookingHold extends Model
{
    /** @use HasFactory<BookingHoldFactory> */
    use HasFactory;

    /** How long a hold keeps its rooms. */
    public const int MINUTES = 20;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BookingHoldStatus::class,
            'arrival_date' => 'immutable_date',
            'departure_date' => 'immutable_date',
            'total_amount' => 'decimal:2',
            'expires_at' => 'immutable_datetime',
            'guest_name' => 'encrypted',
            'guest_email' => 'encrypted',
            'guest_phone' => 'encrypted',
            'guest_nationality' => 'encrypted',
            'special_requests' => 'encrypted',
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
     * @return HasMany<BookingHoldLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BookingHoldLine::class)->orderBy('position');
    }

    /**
     * Holds that keep their rooms: Held and not past the expiry, or Paid. The expiry time alone decides, so no job has to run.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where(fn (Builder $open) => $open
            ->where('booking_holds.status', BookingHoldStatus::Paid->value)
            ->orWhere(fn (Builder $held) => $held
                ->where('booking_holds.status', BookingHoldStatus::Held->value)
                ->where('booking_holds.expires_at', '>', now())));
    }
}
