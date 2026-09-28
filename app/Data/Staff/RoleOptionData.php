<?php

namespace App\Data\Staff;

use App\Models\Role;
use Spatie\LaravelData\Data;

/**
 * A role in a picker. Same shape as enum options, so `EnumSelect` can show it.
 */
class RoleOptionData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}

    public static function fromModel(Role $role): self
    {
        return new self(value: (string) $role->id, label: $role->name);
    }
}
