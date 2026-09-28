<?php

namespace App\Data\Staff;

use App\Enums\DefaultRoleEnum;
use App\Models\Role;
use Spatie\LaravelData\Data;

class RoleRowData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $isDefault,
        public bool $isLocked,
        public int $staffCount,
        public int $permissionCount,
    ) {}

    /**
     * Expects `users_count` and `permissions_count` loaded.
     */
    public static function fromModel(Role $role): self
    {
        return new self(
            id: $role->id,
            name: $role->name,
            isDefault: $role->default_role !== null,
            isLocked: $role->default_role === DefaultRoleEnum::Owner,
            staffCount: (int) $role->getAttribute('users_count'),
            permissionCount: (int) $role->getAttribute('permissions_count'),
        );
    }
}
