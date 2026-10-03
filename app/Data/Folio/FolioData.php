<?php

namespace App\Data\Folio;

use App\Data\Shared\BadgeData;
use Spatie\LaravelData\Data;

/**
 * The open folio of the folio tab. Balance is the posted lines only (null for a unit with no folio yet). Projected
 * total is the posted lines plus the Scheduled rows. `postUnits` are the units a charge can be posted for, and
 * `transferTargets` are the other Open folios of the reservation. Both are empty when the user may not act.
 */
class FolioData extends Data
{
    /**
     * @param  list<FolioRowData>  $rows
     * @param  list<FolioOptionData>  $postUnits
     * @param  list<FolioOptionData>  $transferTargets
     */
    public function __construct(
        public string $key,
        public string $kind,
        public string $name,
        public ?BadgeData $status,
        public ?string $invoiceNumber,
        public ?string $balance,
        public string $projectedTotal,
        public int $lineCount,
        public array $rows,
        public bool $canPostCharge = false,
        public array $postUnits = [],
        public array $transferTargets = [],
    ) {}
}
