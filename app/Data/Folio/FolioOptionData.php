<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One choice in a folio dialog: a unit that can receive a posting, or a folio that can receive a transfer.
 */
class FolioOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
    ) {}
}
