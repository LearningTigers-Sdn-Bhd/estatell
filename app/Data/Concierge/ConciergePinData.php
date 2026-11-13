<?php

namespace App\Data\Concierge;

use App\Services\Kiosk\KioskPinStatus;
use Spatie\LaravelData\Data;

/**
 * The door PIN as the Concierge page shows it: `pending` while the lock has not answered, `ready` with the code and its
 * window, or `failed`.
 */
class ConciergePinData extends Data
{
    public function __construct(
        public string $status,
        public ?string $code,
        public ?string $validFrom,
        public ?string $validUntil,
        public bool $canRetry = false,
    ) {}

    /**
     * `$canRetry` is set only for a failed PIN that asking again could fix (the stay has a lock and the connection is on).
     */
    public static function fromStatus(KioskPinStatus $status, bool $canRetry = false): self
    {
        return new self($status->status, $status->code, $status->validFrom?->toIso8601String(), $status->validUntil?->toIso8601String(), $status->status === KioskPinStatus::FAILED && $canRetry);
    }
}
