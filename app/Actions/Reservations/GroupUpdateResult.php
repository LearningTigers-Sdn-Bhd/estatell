<?php

namespace App\Actions\Reservations;

use App\Models\Reservation;

/**
 * What `UpdateReservationGroup` returns: the reservation, and the units whose posted room lines stay on their own folio
 * because the new billing mode now routes room charges to the Master folio (see `SyncRoutingRules`). A billing mode
 * change is refused with `master_has_payments` while the Master folio holds money; `amount` is the net held, as a
 * decimal string with two places.
 */
class GroupUpdateResult
{
    public const REFUSAL_MASTER_HAS_PAYMENTS = 'master_has_payments';

    /**
     * @param  list<int>  $unitsWithPostedRoomLines
     * @param  array<int, string>  $unitAdvances  Advance money left on the units' own folios, by unit id
     */
    public function __construct(
        public readonly Reservation $reservation,
        public readonly array $unitsWithPostedRoomLines = [],
        public readonly ?string $refusal = null,
        public readonly ?string $amount = null,
        public readonly array $unitAdvances = [],
    ) {}

    public static function refused(Reservation $reservation, string $refusal, ?string $amount = null): self
    {
        return new self($reservation, [], $refusal, $amount);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
