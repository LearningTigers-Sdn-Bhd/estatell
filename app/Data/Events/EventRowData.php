<?php

namespace App\Data\Events;

use App\Data\Shared\BadgeData;
use App\Models\Event;
use Spatie\LaravelData\Data;

/**
 * One row of the Events list. `balance` is the event folio's balance from `FolioBalance`.
 */
class EventRowData extends Data
{
    public function __construct(
        public int $id,
        public string $number,
        public string $name,
        public string $payerName,
        public string $startsOn,
        public string $endsOn,
        public BadgeData $status,
        public string $balance,
    ) {}

    /**
     * Expects `payerGuest` and `payerCompany` to be loaded.
     */
    public static function fromModel(Event $event, string $balance): self
    {
        return new self(
            id: $event->id,
            number: $event->number,
            name: $event->name,
            payerName: $event->payerGuest->full_name ?? $event->payerCompany->name ?? '',
            startsOn: $event->starts_on->toDateString(),
            endsOn: $event->ends_on->toDateString(),
            status: BadgeData::fromEventStatus($event->status),
            balance: $balance,
        );
    }
}
