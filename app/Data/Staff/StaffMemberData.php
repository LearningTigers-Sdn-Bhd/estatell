<?php

namespace App\Data\Staff;

use App\Enums\DefaultRoleEnum;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

class StaffMemberData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?int $roleId,
        public ?string $role,
        public bool $isOwner,
        public bool $isYou,
        public string $joinedAt,
        public ?string $deactivatedAt,
        public ?string $temporaryPasswordExpiresAt,
        public ?string $homeProperty,
    ) {}

    /**
     * Expects `roles` loaded for the current permission team and `property` (the home property). Loaded through
     * `Property::users()`, joined and deactivated are the membership at that property. `homeProperty` names the home
     * property of staff given access from another property of the group, null for the property's own staff; only the
     * home property manages their temp password. See docs/03-staff/04-property-groups.md.
     */
    public static function fromModel(User $user, User $viewer, ?int $propertyId = null): self
    {
        $membership = $user->relationLoaded('pivot') ? $user->getRelation('pivot') : null;
        $joinedAt = $membership?->getAttribute('created_at') ?? $user->created_at;
        $deactivatedAt = $membership !== null ? $membership->getAttribute('deactivated_at') : $user->deactivated_at;
        $fromElsewhere = $propertyId !== null && $user->property_id !== $propertyId;

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roleId: $user->roles->first()?->id,
            role: $user->roles->first()?->name,
            isOwner: $user->roles->first()?->default_role === DefaultRoleEnum::Owner,
            isYou: $user->is($viewer),
            joinedAt: CarbonImmutable::parse($joinedAt)->toDateString(),
            deactivatedAt: $deactivatedAt === null ? null : CarbonImmutable::parse($deactivatedAt)->toDateString(),
            temporaryPasswordExpiresAt: $fromElsewhere ? null : $user->temporary_password_expires_at?->toIso8601String(),
            homeProperty: $fromElsewhere ? $user->property?->name : null,
        );
    }
}
