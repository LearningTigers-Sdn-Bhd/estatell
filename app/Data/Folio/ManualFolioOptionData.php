<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * An Open folio a manual row can post on. `unitId` is the unit of a Unit folio (the row's room is then fixed), else null.
 * `purposes` are the payment purposes a Payment row on it can pick, the default first.
 */
class ManualFolioOptionData extends Data
{
    /**
     * @param  list<array{value: string, label: string}>  $purposes
     */
    public function __construct(
        public int $id,
        public string $label,
        public ?int $unitId,
        public array $purposes,
    ) {}
}
