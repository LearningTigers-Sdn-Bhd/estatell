<?php

namespace App\Navigation;

use App\Data\Navigation\NavItemData;
use App\Features\NativePos;
use App\Services\Platform\Impersonation;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;

class AccountNavigation
{
    public function __construct(private Impersonation $impersonation) {}

    /**
     * @return list<NavItemData>
     */
    public function items(Request $request): array
    {
        $items = [
            new NavItemData(title: __('Profile'), href: route('account.profile.edit', absolute: false), icon: 'user', active: $request->routeIs('account.profile.*')),
        ];

        if (! $this->impersonation->isActive()) {
            $items[] = new NavItemData(title: __('Security'), href: route('account.security.edit', absolute: false), icon: 'shield', active: $request->routeIs('account.security.*', 'account.password.*'));
        }

        $items[] = new NavItemData(title: __('Appearance'), href: route('account.appearance.edit', absolute: false), icon: 'palette', active: $request->routeIs('account.appearance.*'));

        if ($request->user()?->property_id !== null) {
            $items[] = new NavItemData(title: __('Notifications'), href: route('account.notifications.edit', absolute: false), icon: 'bell', active: $request->routeIs('account.notifications.*'));
        }

        $property = $request->user()?->property;

        if ($property !== null && Feature::for($property)->active(NativePos::class)) {
            $items[] = new NavItemData(title: __('POS PIN'), href: route('account.pos-pin.edit', absolute: false), icon: 'lock', active: $request->routeIs('account.pos-pin.*'));
        }

        return $items;
    }
}
