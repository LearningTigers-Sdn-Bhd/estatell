<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One folio in the Post charge Dialog. `unitId` is the room of a Unit folio, which fixes the Room select. A null `id`
 * is a unit with no folio yet: posting creates its own folio. `defaultUnitId` is the room a third-party folio of a guest
 * starts with (the one postable unit the guest is on); staff can change it.
 */
class PostFolioOptionData extends Data
{
    public function __construct(
        public ?int $id,
        public string $label,
        public ?int $unitId,
        public ?int $defaultUnitId = null,
    ) {}
}
