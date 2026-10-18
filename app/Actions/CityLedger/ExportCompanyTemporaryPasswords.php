<?php

namespace App\Actions\CityLedger;

use App\Data\Staff\TemporaryPasswordSlipData;
use App\Enums\TemporaryPasswordExportFormat;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

class ExportCompanyTemporaryPasswords
{
    /**
     * Read the sign-in details of the given company logins that still have an unexpired temp password, and log who
     * took them. The users are marked as shared. The log keeps the format and user ids, never a password.
     *
     * @param  Collection<int, CompanyUser>  $links  Active links of the property, with `company` and `user` loaded.
     * @return BaseCollection<int, TemporaryPasswordSlipData>
     */
    public function __invoke(Property $property, User $actor, Collection $links, TemporaryPasswordExportFormat $format): BaseCollection
    {
        $property->loadMissing('settings');

        $usable = $links
            ->filter(fn (CompanyUser $link): bool => $link->user->temporary_password !== null && $link->user->temporary_password_expires_at->isFuture())
            ->values();

        if ($usable->isNotEmpty()) {
            $userIds = $usable->pluck('user_id')->all();

            User::query()->whereKey($userIds)->update(['temporary_password_shared_at' => now()]);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('company.temp-passwords-exported')
                ->withProperties(['property_id' => $property->id, 'format' => $format->value, 'user_ids' => $userIds])
                ->log('Company temp passwords exported');
        }

        return $usable->toBase()->map(fn (CompanyUser $link): TemporaryPasswordSlipData => TemporaryPasswordSlipData::fromCompanyUser($link->user, $link->company, $property));
    }
}
