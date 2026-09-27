<?php

namespace App\Data\Navigation;

use Spatie\LaravelData\Data;

class NavItemData extends Data
{
    /**
     * @param  string  $icon  Lucide icon name, mapped to a component in the frontend.
     * @param  bool  $external  Opens in a new tab outside the Inertia app.
     */
    public function __construct(
        public string $title,
        public string $href,
        public string $icon,
        public bool $external = false,
    ) {}
}
