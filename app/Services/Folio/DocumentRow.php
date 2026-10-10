<?php

namespace App\Services\Folio;

use App\Enums\TransactionKind;
use Carbon\CarbonImmutable;

/**
 * One folio row as a customer document sees it: plain values, no model. The caller resolves the unit (see
 * LineSourceUnits) and builds the rows. A Scheduled row has no line id. Amounts are decimal strings.
 */
final readonly class DocumentRow
{
    /**
     * @param  string|null  $unitLabel  The group heading of the unit, for example "Room 103 · Tan Wei Ming".
     * @param  int|null  $lineId  The folio line id, null for a Scheduled row.
     * @param  int|null  $reversalOfId  The line this row reverses.
     */
    public function __construct(
        public TransactionKind $kind,
        public string $description,
        public CarbonImmutable $date,
        public int $quantity,
        public string $unitAmount,
        public string $amount,
        public ?int $unitId = null,
        public ?string $unitLabel = null,
        public ?int $lineId = null,
        public ?int $reversalOfId = null,
    ) {}
}
