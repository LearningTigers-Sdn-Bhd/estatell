<?php

namespace App\Data\Navigation;

use App\Enums\NavigationContext;
use Spatie\LaravelData\Data;

/**
 * The staff sidebar: visible contexts and the context of the current route.
 */
class StaffNavigationData extends Data
{
    /**
     * @param  NavigationContext|null  $active  Null on pages outside every context, such as account pages.
     * @param  list<NavContextData>  $contexts
     */
    public function __construct(
        public ?NavigationContext $active,
        public array $contexts,
    ) {}
}
