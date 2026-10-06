<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What Collect now offers at check-in: one row for each tax flagged **Collect at check-in** (a foreign-only tax the
 * guest does not pay shows as exempt with 0), the security deposit, and whether the tax rows are hidden because the
 * room charges route to a Master folio. All amounts are read only text, the server works them out again on submit.
 */
class CheckInCollectionData extends Data
{
    /**
     * @param  list<array{name: string, amount: string, exempt: bool}>  $taxes
     */
    public function __construct(
        public array $taxes,
        public string $taxesTotal,
        public bool $taxesHidden,
        public ?string $deposit,
    ) {}

    public function isEmpty(): bool
    {
        return ($this->taxes === [] || $this->taxesHidden) && $this->deposit === null;
    }
}
