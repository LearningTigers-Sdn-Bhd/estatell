<?php

namespace App\Services\NightAudit;

use App\Enums\AuditCheck;
use App\Enums\AuditExceptionType;

/**
 * One unit that the night audit sheet lists. `message` is the refusal code of a carried exception, and
 * `exceptionId` is the carried exception. `canFixPrice` identifies a stuck package night. A folio check lists one reservation,
 * with no unit and the first folio it is about in `folioId`. `dayUse` is true for a day use unit, which the audit
 * never extends. `canExtend` is true for a unit the Extend fix can move one night: it departs on the business date.
 * `exceptionDate` is the business date of a carried exception's audit, as `Y-m-d`.
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
        public readonly ?int $folioId = null,
        public readonly ?int $exceptionId = null,
        public readonly bool $canFixPrice = false,
        public readonly ?AuditExceptionType $exceptionType = null,
        public readonly bool $dayUse = false,
        public readonly bool $canExtend = false,
        public readonly ?string $exceptionDate = null,
    ) {}
}
