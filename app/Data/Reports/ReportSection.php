<?php

namespace App\Data\Reports;

use App\Enums\Tone;
use Spatie\LaravelData\Data;

/**
 * A group of rows with a heading, such as one boat departure. `rowKeys` are the row keys in print order. `subtotal` is
 * the text under the group (null for none) and `tone` colours it. A group with `printedByDefault` false is left out of
 * the PDF when no rows are selected; a selection prints its rows.
 */
class ReportSection extends Data
{
    /**
     * @param  list<int|string>  $rowKeys
     */
    public function __construct(
        public string $title,
        public array $rowKeys,
        public ?string $subtotal = null,
        public ?Tone $tone = null,
        public bool $printedByDefault = true,
    ) {}
}
