<?php

namespace App\Navigation;

use App\Data\Navigation\NavItemData;
use App\Models\Property;

class SettingsNavigation
{
    /** @return list<NavItemData> */
    public function items(Property $property): array
    {
        return [
            new NavItemData(title: __('Settings'), href: route('settings.edit', ['property' => $property->code], absolute: false), icon: 'settings'),
        ];
    }
}
