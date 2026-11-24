<?php

namespace App\Data\Group;

use App\Data\Shared\BadgeData;
use App\Models\Reservation;
use Spatie\LaravelData\Data;

/**
 * One booking on Group › Reservations, with its property. `guest` is null where the role there does not show guests.
 * The stay columns, the room total and the balance come from the query's joins (see `GroupReservations`). The amounts
 * are in the property's currency (`currency`).
 */
class GroupReservationRowData extends Data
{
    public function __construct(
        public int $id,
        public string $propertyCode,
        public string $propertyName,
        public string $reference,
        public ?string $guest,
        public ?string $arrival,
        public ?string $departure,
        public int $rooms,
        public int $pax,
        public BadgeData $status,
        public ?string $source,
        public string $total,
        public string $balance,
        public string $currency,
    ) {}

    public static function fromModel(Reservation $reservation, bool $canViewGuest): self
    {
        return new self(
            id: $reservation->id,
            propertyCode: $reservation->property->code,
            propertyName: $reservation->property->name,
            reference: $reservation->reference,
            guest: $canViewGuest ? $reservation->booker->full_name : null,
            arrival: $reservation->getAttribute('first_arrival') === null ? null : substr((string) $reservation->getAttribute('first_arrival'), 0, 10),
            departure: $reservation->getAttribute('last_departure') === null ? null : substr((string) $reservation->getAttribute('last_departure'), 0, 10),
            rooms: (int) $reservation->getAttribute('live_units'),
            pax: (int) $reservation->getAttribute('adults') + (int) $reservation->getAttribute('children'),
            status: BadgeData::fromReservationStatus($reservation->status),
            source: $reservation->bookingSource?->name,
            total: number_format((float) $reservation->getAttribute('stay_total'), 2, '.', ''),
            balance: number_format((float) $reservation->getAttribute('balance'), 2, '.', ''),
            currency: $reservation->property->settings->currency->getCurrencyCode(),
        );
    }

    /**
     * @return list<string>
     */
    public static function csvHeadings(): array
    {
        return [__('Property'), __('Reference'), __('Guest'), __('Arrival'), __('Departure'), __('Rooms'), __('Pax'), __('Status'), __('Source'), __('Currency'), __('Room total'), __('Balance')];
    }

    /**
     * @return list<string|int|null>
     */
    public function csv(): array
    {
        return [$this->propertyName, $this->reference, $this->guest, $this->arrival, $this->departure, $this->rooms, $this->pax, $this->status->label, $this->source, $this->currency, $this->total, $this->balance];
    }
}
