<?php

namespace App\Data\Housekeeping;

use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * A staff member a room can be assigned to.
 */
class HousekeepingStaffData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self($user->id, $user->name);
    }
}
