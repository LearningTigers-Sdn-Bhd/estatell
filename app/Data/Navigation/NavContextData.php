<?php

namespace App\Data\Navigation;

use App\Enums\NavigationContext;
use Spatie\LaravelData\Data;

/**
 * One staff area with its visible groups.
 */
class NavContextData extends Data
{
    /**
     * @param  string  $href  The context's first item, opened by its tab in the page header.
     * @param  list<NavGroupData>  $groups
     */
    public function __construct(
        public NavigationContext $key,
        public string $label,
        public string $icon,
        public string $href,
        public array $groups,
    ) {}
}
