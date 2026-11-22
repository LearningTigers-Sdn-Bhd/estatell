<?php

namespace App\Actions\Platform;

use App\Models\PropertyGroup;
use App\Models\User;

class RenamePropertyGroup
{
    public function __invoke(User $admin, PropertyGroup $group, string $name): void
    {
        $old = $group->name;
        $group->update(['name' => trim($name)]);

        activity()
            ->performedOn($group)
            ->causedBy($admin)
            ->event('property-group.renamed')
            ->withProperties(['old' => $old, 'new' => $group->name])
            ->log('Property group renamed');
    }
}
