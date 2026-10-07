<?php

namespace App\Data\Navigation;

use Spatie\LaravelData\Data;

class NavItemData extends Data
{
    /**
     * @param  string  $icon  Lucide icon name, mapped to a component in the frontend.
     * @param  bool  $external  Opens in a new tab outside the Inertia app.
     * @param  bool  $active  Set by the server from the route name.
     * @param  string|null  $action  Opens a sheet instead of going to `href`. `href` is then the URL the sheet loads.
     */
    public function __construct(
        public string $title,
        public string $href,
        public string $icon,
        public bool $external = false,
        public bool $active = false,
        public ?string $action = null,
    ) {}
}
