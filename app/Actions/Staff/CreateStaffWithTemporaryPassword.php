<?php

namespace App\Actions\Staff;

use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Services\Staff\TemporaryPasswordGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateStaffWithTemporaryPassword
{
    /**
     * Days a temp password works after it is made or reset.
     */
    public const int VALID_DAYS = 14;

    public function __construct(private TemporaryPasswordGenerator $generator) {}

    /**
     * Create a staff user who signs in with a generated temp password and must then set their own.
     *
     * The owner vouches for the person, so the email is marked verified. The request has already checked
     * that the email has no account and no open invitation here.
     */
    public function __invoke(Property $property, User $actor, string $name, string $email, Role $role): User
    {
        return DB::transaction(function () use ($property, $actor, $name, $email, $role): User {
            $temporaryPassword = $this->generator->generate();

            $user = new User(['name' => $name, 'email' => Str::lower($email), 'password' => $temporaryPassword]);
            $user->forceFill([
                'property_id' => $property->id,
                'email_verified_at' => now(),
                'temporary_password' => $temporaryPassword,
                'temporary_password_expires_at' => now()->addDays(self::VALID_DAYS),
                'must_change_password' => true,
            ])->save();

            $previousTeamId = getPermissionsTeamId();
            setPermissionsTeamId($property->id);

            try {
                $user->assignRole($role);
            } finally {
                setPermissionsTeamId($previousTeamId);
            }

            activity()
                ->performedOn($user)
                ->causedBy($actor)
                ->event('staff.created-with-temp-password')
                ->withProperties(['property_id' => $property->id, 'email' => $user->email, 'role' => $role->name])
                ->log('Staff created with a temp password');

            return $user;
        });
    }
}
