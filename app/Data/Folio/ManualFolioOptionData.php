<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * An Open folio a manual row can post on. `unitId` is the unit of a Unit folio (the row's room is then fixed), else null.
 */
class ManualFolioOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
        public ?int $unitId,
    ) {}
}
