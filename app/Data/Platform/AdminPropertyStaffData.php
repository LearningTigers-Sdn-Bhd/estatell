<?php

namespace App\Data\Platform;

use App\Models\User;
use Spatie\LaravelData\Data;

class AdminPropertyStaffData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public bool $verified,
        public string $createdAt,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            verified: $user->email_verified_at !== null,
            createdAt: $user->created_at->toDateString(),
        );
    }
}
