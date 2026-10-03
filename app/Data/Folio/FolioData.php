<?php

namespace App\Data\Folio;

use App\Data\Shared\BadgeData;
use Spatie\LaravelData\Data;

/**
 * The open folio of the folio tab. Balance is the posted lines only (null for a unit with no folio yet). Projected
 * total is the posted lines plus the Scheduled rows.
 */
class FolioData extends Data
{
    /**
     * @param  list<FolioRowData>  $rows
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
    ) {}
}
