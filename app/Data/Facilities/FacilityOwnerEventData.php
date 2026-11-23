<?php

namespace App\Data\Facilities;

use App\Models\Event;
use Spatie\LaravelData\Data;

/**
 * An event that takes bookings on the date, in the Timeline's Book facility For step. It carries what the Book
 * facility Sheet needs for an event owner. Dates are `Y-m-d`.
 */
class FacilityOwnerEventData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $number,
        public string $startsOn,
        public string $endsOn,
        public string $payerName,
    ) {}

    /**
     * Expects `payerGuest` and `payerCompany` to be loaded.
     */
    public static function fromModel(Event $event): self
    {
        return new self(
            id: $event->id,
            name: $event->name,
            number: $event->number,
            startsOn: $event->starts_on->toDateString(),
            endsOn: $event->ends_on->toDateString(),
            payerName: $event->payerGuest->full_name ?? $event->payerCompany->name ?? '',
        );
    }
}
