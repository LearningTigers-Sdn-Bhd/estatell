<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One unit in the group add-on preview. `nights` is null for an item that is not nightly. `total` is before tax, and
 * null when the unit would get no rows or the amount is missing; `note` then says why ("Not added").
 */
class GroupAddOnPreviewLineData extends Data
{
    public function __construct(
        public int $unitId,
        public string $label,
        public int $guests,
        public ?int $nights,
        public ?string $total,
        public ?string $note,
    ) {}
}
