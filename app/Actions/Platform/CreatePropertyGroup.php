<?php

namespace App\Actions\Platform;

use App\Models\PropertyGroup;
use App\Models\User;

class CreatePropertyGroup
{
    /**
     * Create an empty group; properties are added to it one by one. See docs/03-staff/04-property-groups.md.
     */
    public function __invoke(User $admin, string $name): PropertyGroup
    {
        $group = PropertyGroup::query()->create(['name' => trim($name)]);

        activity()
            ->performedOn($group)
            ->causedBy($admin)
            ->event('property-group.created')
            ->log('Property group created');

        return $group;
    }
}
