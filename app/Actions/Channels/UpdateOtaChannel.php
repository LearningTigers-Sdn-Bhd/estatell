<?php

namespace App\Actions\Channels;

use App\Models\OtaChannel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateOtaChannel
{
    /**
     * Rename a channel. The code stays the same, so existing logins keep their channel. Returns false for the
     * locked Other row. A name that does not change is a no-op.
     */
    public function __invoke(User $admin, OtaChannel $channel, string $name): bool
    {
        return DB::transaction(function () use ($admin, $channel, $name): bool {
            $channel = OtaChannel::query()->lockForUpdate()->findOrFail($channel->id);

            if ($channel->is_other) {
                return false;
            }

            if ($channel->name === $name) {
                return true;
            }

            $previousName = $channel->name;
            $channel->update(['name' => $name]);

            activity()
                ->performedOn($channel)
                ->causedBy($admin)
                ->event('ota_channel.renamed')
                ->withProperties(['from' => $previousName, 'to' => $name])
                ->log('OTA channel renamed');

            return true;
        });
    }
}
