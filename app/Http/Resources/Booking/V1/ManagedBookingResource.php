<?php

namespace App\Http\Resources\Booking\V1;

use App\Models\BookingHold;
use App\Models\GatewayRefund;
use App\Models\ReservationCancellationTerm;
use App\Models\ReservationUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A confirmed booking as its guest sees it on the Manage booking page. The caller has loaded `property.settings`, and
 * `reservation` with its `booker` and `units.roomType`. See docs/29-booking-engine/02-api.md#manage-booking.
 *
 * @property BookingHold $resource
 */
class ManagedBookingResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hold = $this->resource;
        $reservation = $hold->reservation;
        $terms = ReservationCancellationTerm::query()->where('property_id', $hold->property_id)->where('reservation_id', $reservation->id)->first();
        $refund = GatewayRefund::query()
            ->where('property_id', $hold->property_id)
            ->whereHas('gatewayPayment', fn ($payment) => $payment->where('booking_hold_id', $hold->id))
            ->latest('id')
            ->first();

        return [
            'reference' => $reservation->reference,
            'short_code' => $reservation->short_code,
            'status' => $reservation->status->value,
            'arrival' => $hold->arrival_date->toDateString(),
            'departure' => $hold->departure_date->toDateString(),
            'guest' => $reservation->booker?->full_name,
            'rooms' => array_values($reservation->units->map(fn (ReservationUnit $unit): array => [
                'room_type' => $unit->roomType->name,
                'status' => $unit->status->value,
            ])->all()),
            'currency' => $hold->property->settings?->currency->getCurrencyCode(),
            'amount_paid' => $hold->total_amount,
            'refund' => $refund === null ? null : ['status' => $refund->status->value, 'amount' => $refund->amount],
            'cancellation_terms' => $terms === null ? null : [
                'free_until_days' => $terms->free_until_days,
                'text_en' => $terms->text_en,
                'text_ms' => $terms->text_ms,
            ],
        ];
    }
}
