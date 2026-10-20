<?php

namespace App\Data\Onboarding;

use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * One saved staff user as a read-only row of the Team table. Their sign-in details are never sent.
 */
class OnboardingStaffData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $roleName,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roleName: $user->roles->first()->name ?? '',
        );
    }
}
