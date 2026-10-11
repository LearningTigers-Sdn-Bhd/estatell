<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * The scheduled choices of one room on the Move charges page. `fromFolioId` is the folio its Scheduled rows are
 * charged to now (null while the room has no folio yet). Both choices move only rows that do not already go to the
 * target.
 */
class MoveScheduleData extends Data
{
    /**
     * @param  list<MoveChoiceData>  $choices
     */
    public function __construct(
        public int $unitId,
        public string $label,
        public ?int $fromFolioId,
        public array $choices,
    ) {}
}
