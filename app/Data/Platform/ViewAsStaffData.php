<?php

namespace App\Data\Platform;

use Spatie\LaravelData\Data;

class ViewAsStaffData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $role,
        public bool $isOwner,
    ) {}
}
