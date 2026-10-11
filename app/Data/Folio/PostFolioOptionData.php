<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One Open folio in the Post charge Dialog. `unitId` is the room of a Unit folio, which fixes the Room select.
 */
class PostFolioOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
        public ?int $unitId,
    ) {}
}
