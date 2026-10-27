<?php

namespace App\Actions\Reservations;

use App\Models\UnitGuest;

/**
 * What `AttachGuest` and `ReplaceUnitGuest` return: the new occupant row, or a refusal code. `taxWarning` is set when a new primary guest on a CheckedOut unit may change a foreign-only tax.
 */
class AttachGuestResult
{
    public const REFUSAL_GUEST_NOT_FOUND = 'guest_not_found';

    public const REFUSAL_UNIT_NOT_OPEN = 'unit_not_open';

    public const REFUSAL_ALREADY_ATTACHED = 'already_attached';

    public const REFUSAL_BED_UNIT_FULL = 'bed_unit_full';

    public const REFUSAL_UNIT_FULL = 'unit_full';

    public const REFUSAL_HAS_REGISTRATION_CARD = 'has_registration_card';

    private function __construct(
        public readonly ?UnitGuest $unitGuest,
        public readonly ?string $refusal,
        public readonly ?string $taxWarning = null,
    ) {}

    public static function attached(UnitGuest $unitGuest, ?string $taxWarning = null): self
    {
        return new self($unitGuest, null, $taxWarning);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
