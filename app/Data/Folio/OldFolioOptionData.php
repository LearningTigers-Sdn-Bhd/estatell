<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * An item the old folio page picks from. `kind` is `charge`, `tax` or `payment`.
 */
class OldFolioOptionData extends Data
{
    public function __construct(
        public string $kind,
        public int $id,
        public string $label,
    ) {}
}
