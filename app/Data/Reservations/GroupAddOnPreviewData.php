<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The preview of the group add-on Dialog: one line per picked unit and the total before tax of the lines that have one.
 * See docs/12-reservations/04-add-ons.md#group-add-on.
 */
class GroupAddOnPreviewData extends Data
{
    /**
     * @param  list<GroupAddOnPreviewLineData>  $lines
     */
    public function __construct(
        public array $lines,
        public string $total,
    ) {}
}
