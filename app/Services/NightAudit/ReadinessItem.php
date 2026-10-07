<?php

namespace App\Services\NightAudit;

use App\Enums\AuditCheck;

/**
 * One unit that the night audit sheet lists. `message` is the refusal code of a carried exception.
 */
class ReadinessItem
{
    public function __construct(
        public readonly AuditCheck $check,
        public readonly int $reservationId,
        public readonly ?int $reservationUnitId,
        public readonly string $reference,
        public readonly ?string $guest,
        public readonly string $detail,
        public readonly ?string $message = null,
    ) {}
}
