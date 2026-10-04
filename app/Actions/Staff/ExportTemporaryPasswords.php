<?php

namespace App\Actions\Staff;

use App\Data\Staff\TemporaryPasswordSlipData;
use App\Enums\TemporaryPasswordExportFormat;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

class ExportTemporaryPasswords
{
    /**
     * Read the sign-in details of the given staff who still have an unexpired temp password, and log who took them.
     * The log keeps the format and user ids, never a password.
     *
     * @param  Collection<int, User>  $users  with `roles` loaded for the property's permission team
     * @return BaseCollection<int, TemporaryPasswordSlipData>
     */
    public function __invoke(Property $property, User $actor, Collection $users, TemporaryPasswordExportFormat $format): BaseCollection
    {
        $property->loadMissing('settings');

        $usable = $users
            ->filter(fn (User $user): bool => $user->temporary_password !== null && $user->temporary_password_expires_at->isFuture())
            ->values();

        if ($usable->isNotEmpty()) {
            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('staff.temp-passwords-exported')
                ->withProperties(['property_id' => $property->id, 'format' => $format->value, 'user_ids' => $usable->modelKeys()])
                ->log('Staff temp passwords exported');
        }

        return $usable->toBase()->map(fn (User $user): TemporaryPasswordSlipData => TemporaryPasswordSlipData::fromModel($user, $property));
    }
}
