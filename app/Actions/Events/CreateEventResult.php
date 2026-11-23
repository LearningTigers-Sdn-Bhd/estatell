<?php

namespace App\Actions\Events;

use App\Actions\Facilities\FacilityBookingResult;
use App\Models\Event;

/**
 * The outcome of `CreateEvent::withBookings()`: the event, the refused guest row (an `AttachGuestResult` refusal), or
 * the refused facility line (its index on the page) with the refusal.
 */
final class CreateEventResult
{
    private function __construct(
        public readonly ?Event $event,
        public readonly ?int $line,
        public readonly ?FacilityBookingResult $refusal,
        public readonly ?string $guestRefusal = null,
    ) {}

    public static function created(Event $event): self
    {
        return new self($event, null, null);
    }

    public static function refused(int $line, FacilityBookingResult $refusal): self
    {
        return new self(null, $line, $refusal);
    }

    public static function guestRefused(string $refusal): self
    {
        return new self(null, null, null, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null || $this->guestRefusal !== null;
    }
}
