<?php

namespace App\Services\Folio;

use Carbon\CarbonImmutable;

/**
 * One printed row: a line, or several equal nights merged into one. `nights` is 1 for a single line.
 */
final readonly class DocumentLineRow
{
    public function __construct(
        public string $description,
        public CarbonImmutable $dateFrom,
        public CarbonImmutable $dateTo,
        public int $nights,
        public string $unitAmount,
        public string $amount,
        public ?string $room = null,
    ) {}

    /**
     * "Room, 3 nights × 250.00" for merged nights, else the description.
     */
    public function text(): string
    {
        if ($this->nights === 1) {
            return $this->description;
        }

        return $this->description.', '.trans_choice(':count night|:count nights', $this->nights).' × '.$this->unitAmount;
    }
}
