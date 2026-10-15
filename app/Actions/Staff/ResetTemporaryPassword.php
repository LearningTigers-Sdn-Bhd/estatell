<?php

namespace App\Actions\Staff;

use App\Enums\DefaultRoleEnum;
use App\Models\Role;
use App\Models\User;
use App\Services\Staff\TemporaryPasswordGenerator;
use Illuminate\Support\Facades\DB;

class ResetTemporaryPassword
{
    public function __construct(private TemporaryPasswordGenerator $generator) {}

    /**
     * Replace a staff user's password with a new temp password that they must change after signing in.
     * The password changes, so `auth.session` signs them out everywhere.
     *
     * Returns false for yourself, an Owner or a deactivated user.
     */
    public function __invoke(User $actor, User $staff): bool
    {
        if ($actor->is($staff)) {
            return false;
        }

        return DB::transaction(function () use ($actor, $staff): bool {
            $staff = User::query()->lockForUpdate()->findOrFail($staff->id);

            if ($staff->deactivated_at !== null) {
                return false;
            }

            $ownerRole = Role::query()->where('property_id', $staff->property_id)->where('default_role', DefaultRoleEnum::Owner)->firstOrFail();

            if ($ownerRole->users()->whereKey($staff->id)->exists()) {
                return false;
            }

            $temporaryPassword = $this->generator->generate();

            $staff->forceFill([
                'password' => $temporaryPassword,
                'temporary_password' => $temporaryPassword,
                'temporary_password_expires_at' => now()->addDays(CreateStaffWithTemporaryPassword::VALID_DAYS),
                'must_change_password' => true,
                'temporary_password_shared_at' => null,
            ])->save();

            activity()
                ->performedOn($staff)
                ->causedBy($actor)
                ->event('staff.temp-password-reset')
                ->withProperties(['property_id' => $staff->property_id, 'email' => $staff->email])
                ->log('Staff temp password reset');

            return true;
        });
    }
}
