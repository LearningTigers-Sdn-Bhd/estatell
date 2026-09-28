<?php

namespace App\Actions\Onboarding;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;

class CompleteOwnerSetup
{
    public function __invoke(User $owner, string $password): bool
    {
        return DB::transaction(function () use ($owner, $password): bool {
            $owner = User::query()->lockForUpdate()->findOrFail($owner->id);

            if ($owner->hasVerifiedEmail()) {
                return false;
            }

            $owner->forceFill([
                'password' => $password,
                'email_verified_at' => now(),
            ])->save();

            event(new Verified($owner));

            activity()
                ->performedOn($owner)
                ->causedBy($owner)
                ->event('owner.account-setup-completed')
                ->withProperties(['property_id' => $owner->property_id])
                ->log('Owner account setup completed');

            return true;
        });
    }
}
