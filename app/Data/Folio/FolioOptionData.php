<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One choice in a folio dialog: a unit that can receive a posting.
 */
class FolioOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
    ) {}
}
