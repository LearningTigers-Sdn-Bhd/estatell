<?php

namespace App\Actions\FrontDesk;

use App\Models\Reservation;

/**
 * What `CancelUnits` returns: the cancelled count and the total fee before tax, or a refusal code with the unit that
 * caused it. A refusal means nothing was cancelled.
 */
class CancelUnitsResult
{
    public const REFUSAL_NO_UNITS = 'no_units_selected';

    private function __construct(
        public readonly ?Reservation $reservation,
        public readonly ?string $refusal,
        public readonly int $cancelled = 0,
        public readonly string $totalFee = '0.00',
        public readonly ?int $failedUnitId = null,
        public readonly ?string $failedRoom = null,
    ) {}

    public static function done(Reservation $reservation, int $cancelled, string $totalFee): self
    {
        return new self($reservation, null, $cancelled, $totalFee);
    }

    /**
     * @param  string|null  $room  The room or room type of the unit that refused, for the message.
     */
    public static function refused(string $refusal, ?int $unitId = null, ?string $room = null): self
    {
        return new self(null, $refusal, 0, '0.00', $unitId, $room);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
