<?php

namespace App\Services\Folio;

use App\Enums\TransactionKind;
use Carbon\CarbonImmutable;

/**
 * One folio row as a customer document sees it: plain values, no model. The caller resolves the unit (see
 * LineSourceUnits) and builds the rows, and sets `nightly` from the line source, the same way for every document:
 * a unit night and its inclusions are nightly; a child line (discount, tax) takes its parent's flag; an add-on charged
 * PerNight or PerGuestNight is nightly; PerItem, PerStay and PerGuest add-ons, manual charges and policy fees (source
 * is the reservation unit) are not. Only nightly rows merge into "3 nights × 250.00". A Scheduled row has no line id. Amounts are decimal strings.
 */
final readonly class DocumentRow
{
    /**
     * @param  string|null  $unitLabel  The group heading of the unit, for example "Room 103 · Tan Wei Ming".
     * @param  string|null  $room  The unit's short room label for the Room column: the room number, or the room type
     *                             name when no room is assigned yet. Null for a line with no unit.
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
        public bool $nightly = false,
        public ?string $room = null,
    ) {}
}
