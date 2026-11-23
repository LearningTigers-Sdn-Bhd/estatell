<?php

namespace App\Models;

use App\Enums\FacilityBookingStatus;
use Carbon\CarbonImmutable;
use Database\Factories\FacilityBookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One booking of one facility for a time, a quantity and a folio (Charge to). It belongs to an event or a
 * reservation. See docs/33-facilities/04-facility-bookings.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int $facility_id
 * @property int|null $event_id
 * @property int|null $reservation_id
 * @property int $folio_id
 * @property CarbonImmutable $booking_date
 * @property string $starts_at HH:MM:SS
 * @property string $ends_at HH:MM:SS
 * @property int $quantity
 * @property string|null $unit_amount Null when the facility is charged by hand.
 * @property FacilityBookingStatus $status
 * @property string|null $notes
 * @property int|null $created_by
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['facility_id', 'event_id', 'reservation_id', 'folio_id', 'booking_date', 'starts_at', 'ends_at', 'quantity', 'unit_amount', 'status', 'notes', 'created_by', 'cancelled_at', 'cancelled_by'])]
class FacilityBooking extends Model
{
    /** @use HasFactory<FacilityBookingFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'booking_date' => 'immutable_date',
            'quantity' => 'integer',
            'unit_amount' => 'decimal:2',
            'status' => FacilityBookingStatus::class,
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Facility, $this> */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Folio, $this> */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /** @param Builder<FacilityBooking> $query */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', '!=', FacilityBookingStatus::Cancelled->value);
    }

    /** @param Builder<FacilityBooking> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('booking_date')->orderBy('starts_at')->orderBy('id');
    }
}
