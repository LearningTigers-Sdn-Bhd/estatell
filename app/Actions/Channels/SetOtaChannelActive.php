<?php

namespace App\Actions\Channels;

use App\Models\OtaChannel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetOtaChannelActive
{
    /**
     * Turn a channel on or off for owners. A deactivated channel stays on existing logins. Returns false for the
     * locked Other row, and when the channel already has that state.
     */
    public function __invoke(User $admin, OtaChannel $channel, bool $active): bool
    {
        return DB::transaction(function () use ($admin, $channel, $active): bool {
            $channel = OtaChannel::query()->lockForUpdate()->findOrFail($channel->id);

            if ($channel->is_other || $active === $channel->is_active) {
                return false;
            }

            $channel->update(['is_active' => $active]);

            activity()
                ->performedOn($channel)
                ->causedBy($admin)
                ->event($active ? 'ota_channel.activated' : 'ota_channel.deactivated')
                ->log($active ? 'OTA channel turned on' : 'OTA channel turned off');

            return true;
        });
    }
}
