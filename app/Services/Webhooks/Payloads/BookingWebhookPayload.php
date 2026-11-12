<?php

namespace App\Services\Webhooks\Payloads;

use App\Enums\WebhookEvent;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Services\Folio\FolioBalance;
use App\Services\Reservations\StayTotal;
use Illuminate\Database\Eloquent\Model;

/**
 * The booking with its units, rooms, guests, total and balance. Booking events take the reservation; stay events take
 * the unit and also send `unit_id`. See docs/32-webhooks/01-outgoing-webhooks.md.
 */
class BookingWebhookPayload implements WebhookPayloadBuilder
{
    public function __construct(
        private GuestWebhookPayload $guests,
        private StayTotal $stayTotal,
        private FolioBalance $folioBalance,
    ) {}

    public function build(WebhookEvent $event, Model $subject, array $context): ?array
    {
        if ($subject instanceof ReservationUnit) {
            $reservation = Reservation::query()->find($subject->reservation_id);

            return $reservation === null ? null : ['booking' => $this->booking($reservation), 'unit_id' => $subject->id];
        }

        return $subject instanceof Reservation ? ['booking' => $this->booking($subject)] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function booking(Reservation $reservation): array
    {
        $reservation->loadMissing([
            'property.settings',
            'booker',
            'bookingSource',
            'company',
            'units' => fn ($units) => $units->orderBy('id'),
            'units.roomType',
            'units.ratePlan',
            'units.nights.room',
            'units.guests.guest',
        ]);

        return [
            'id' => $reservation->id,
            'reference' => $reservation->reference,
            'short_code' => $reservation->short_code,
            'status' => $reservation->status->value,
            'source' => $reservation->bookingSource->name,
            'external_reference' => $reservation->external_reference,
            'group_name' => $reservation->group_name,
            'company' => $reservation->company?->name,
            'booker' => $this->guests->contact($reservation->booker),
            'currency' => $reservation->property->settings?->currency->getCurrencyCode(),
            'total' => $this->stayTotal->forReservation($reservation),
            'balance' => $this->folioBalance->forReservations([$reservation->id])[$reservation->id],
            'units' => $reservation->units->map(fn (ReservationUnit $unit): array => $this->unit($unit))->values()->all(),
            'created_at' => $reservation->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unit(ReservationUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'status' => $unit->status->value,
            'stay_type' => $unit->stay_type->value,
            'room_type' => $unit->roomType->name,
            'rate_plan' => $unit->ratePlan->name,
            'arrival_date' => $unit->arrival_date->toDateString(),
            'departure_date' => $unit->departure_date->toDateString(),
            'arrival_time' => substr($unit->arrival_time, 0, 5),
            'departure_time' => substr($unit->departure_time, 0, 5),
            'adults' => $unit->adults,
            'children' => $unit->children,
            'rooms' => $unit->nights->map(fn (UnitNight $night): ?string => $night->room?->number)->filter()->unique()->values()->all(),
            'checked_in_at' => $unit->checked_in_at?->toIso8601String(),
            'checked_out_at' => $unit->checked_out_at?->toIso8601String(),
            'guests' => $unit->guests
                ->filter(fn (UnitGuest $unitGuest): bool => $unitGuest->guest->anonymized_at === null)
                ->map(fn (UnitGuest $unitGuest): array => [...$this->guests->contact($unitGuest->guest), 'is_primary' => $unitGuest->is_primary])
                ->values()
                ->all(),
        ];
    }
}
