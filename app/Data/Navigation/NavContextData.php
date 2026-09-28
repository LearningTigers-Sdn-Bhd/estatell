<?php

namespace App\Data\Navigation;

use App\Enums\NavigationContext;
use Spatie\LaravelData\Data;

/**
 * One sidebar context with its visible groups.
 */
class NavContextData extends Data
{
    /**
     * @param  string  $href  The context's first item, opened when there is no last visited page.
     * @param  list<NavGroupData>  $groups
     */
    public function __construct(
        public NavigationContext $key,
        public string $label,
        public string $icon,
        public string $shortcut,
        public string $href,
        public array $groups,
    ) {}
}
