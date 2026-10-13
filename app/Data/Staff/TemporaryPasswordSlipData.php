<?php

namespace App\Data\Staff;

use App\Models\Property;
use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * One person's sign-in details, for the copy message and exports. Never sent as page props.
 */
class TemporaryPasswordSlipData extends Data
{
    public function __construct(
        public string $property,
        public string $name,
        public string $email,
        public ?string $role,
        public string $temporaryPassword,
        public string $expiresOn,
        public string $loginUrl,
    ) {}

    /**
     * A partner user's sign-in details. The partner name takes the place of the property name. Expects an unexpired
     * temp password and a loaded or loadable `partner`.
     */
    public static function fromPartner(User $user): self
    {
        return new self(
            property: $user->partner->name,
            name: $user->name,
            email: $user->email,
            role: null,
            temporaryPassword: (string) $user->temporary_password,
            expiresOn: $user->temporary_password_expires_at->setTimezone(config('app.timezone'))->format('j M Y'),
            loginUrl: route('login'),
        );
    }

    /**
     * Expects `roles` loaded for the property's permission team and an unexpired temp password.
     */
    public static function fromModel(User $user, Property $property): self
    {
        $timeZone = $property->settings->time_zone ?? config('app.timezone');

        return new self(
            property: $property->name,
            name: $user->name,
            email: $user->email,
            role: $user->roles->first()?->name,
            temporaryPassword: (string) $user->temporary_password,
            expiresOn: $user->temporary_password_expires_at->setTimezone($timeZone)->format('j M Y'),
            loginUrl: route('login'),
        );
    }
}
