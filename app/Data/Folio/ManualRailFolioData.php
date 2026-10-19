<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * The balance of one folio the manual rows touch, now and after posting, with the same name the folio tab uses.
 */
class ManualRailFolioData extends Data
{
    public function __construct(
        public int $folioId,
        public string $label,
        public string $now,
        public string $after,
    ) {}
}
