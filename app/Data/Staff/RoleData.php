<?php

namespace App\Data\Staff;

use App\Enums\DefaultRoleEnum;
use App\Models\Role;
use Spatie\LaravelData\Data;

class RoleData extends Data
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $isDefault,
        public bool $isLocked,
        public array $permissions,
    ) {}

    public static function fromModel(Role $role): self
    {
        return new self(
            id: $role->id,
            name: $role->name,
            isDefault: $role->default_role !== null,
            isLocked: $role->default_role === DefaultRoleEnum::Owner,
            permissions: $role->permissions()->orderBy('name')->pluck('name')->all(),
        );
    }
}
