<?php

namespace App\Data\Staff;

use App\Enums\DefaultRoleEnum;
use App\Models\Role;
use Spatie\LaravelData\Data;

/**
 * One column of the roles grid.
 */
class RoleRowData extends Data
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $isDefault,
        public bool $isLocked,
        public int $staffCount,
        public array $permissions,
    ) {}

    /**
     * Expects `users_count` and `permissions` loaded.
     */
    public static function fromModel(Role $role): self
    {
        return new self(
            id: $role->id,
            name: $role->name,
            isDefault: $role->default_role !== null,
            isLocked: $role->default_role === DefaultRoleEnum::Owner,
            staffCount: (int) $role->getAttribute('users_count'),
            permissions: array_values($role->permissions->pluck('name')->sort()->all()),
        );
    }
}
