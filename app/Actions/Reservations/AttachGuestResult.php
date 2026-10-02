<?php

namespace App\Actions\Reservations;

use App\Models\UnitGuest;

/**
 * What `AttachGuest` returns: the new occupant row, or a refusal code.
 */
class AttachGuestResult
{
    public const REFUSAL_GUEST_NOT_FOUND = 'guest_not_found';

    public const REFUSAL_UNIT_NOT_OPEN = 'unit_not_open';

    public const REFUSAL_ALREADY_ATTACHED = 'already_attached';

    public const REFUSAL_BED_UNIT_FULL = 'bed_unit_full';

    private function __construct(
        public readonly ?UnitGuest $unitGuest,
        public readonly ?string $refusal,
    ) {}

    public static function attached(UnitGuest $unitGuest): self
    {
        return new self($unitGuest, null);
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
