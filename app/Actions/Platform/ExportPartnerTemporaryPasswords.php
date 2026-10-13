<?php

namespace App\Actions\Platform;

use App\Data\Staff\TemporaryPasswordSlipData;
use App\Enums\TemporaryPasswordExportFormat;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

class ExportPartnerTemporaryPasswords
{
    /**
     * Read the sign-in details of the given partner users who still have an unexpired temp password, and log who
     * took them. The log keeps the format and user ids, never a password.
     *
     * @param  Collection<int, User>  $users
     * @return BaseCollection<int, TemporaryPasswordSlipData>
     */
    public function __invoke(User $admin, Collection $users, TemporaryPasswordExportFormat $format): BaseCollection
    {
        $users->loadMissing('partner');

        $usable = $users
            ->filter(fn (User $user): bool => $user->temporary_password !== null && $user->temporary_password_expires_at->isFuture())
            ->values();

        if ($usable->isNotEmpty()) {
            activity()
                ->causedBy($admin)
                ->event('partner.temp-passwords-exported')
                ->withProperties(['format' => $format->value, 'user_ids' => $usable->modelKeys()])
                ->log('Partner temp passwords exported');
        }

        return $usable->toBase()->map(fn (User $user): TemporaryPasswordSlipData => TemporaryPasswordSlipData::fromPartner($user));
    }
}
