<?php

namespace App\Data\Navigation;

use Spatie\LaravelData\Data;

/**
 * A labelled group of sidebar items. Groups are never collapsible.
 */
class NavGroupData extends Data
{
    /**
     * @param  list<NavItemData>  $items
     */
    public function __construct(
        public string $label,
        public array $items,
    ) {}
}
