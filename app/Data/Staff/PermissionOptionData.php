<?php

namespace App\Data\Staff;

use App\Enums\PermissionEnum;
use Spatie\LaravelData\Data;

class PermissionOptionData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public string $group,
    ) {}

    public static function fromEnum(PermissionEnum $permission): self
    {
        return new self(value: $permission->value, label: $permission->label(), group: $permission->group());
    }
}
