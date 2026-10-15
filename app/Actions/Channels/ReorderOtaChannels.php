<?php

namespace App\Actions\Channels;

use App\Models\OtaChannel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderOtaChannels
{
    /**
     * Set the display order of every channel, active or not. Returns false unless the ids are exactly the
     * channels that exist now.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(User $admin, array $orderedIds): bool
    {
        return DB::transaction(function () use ($admin, $orderedIds): bool {
            $existing = OtaChannel::query()->lockForUpdate()->pluck('id')->all();

            if (count($orderedIds) !== count($existing) || array_diff($orderedIds, $existing) !== [] || array_diff($existing, $orderedIds) !== []) {
                return false;
            }

            foreach ($orderedIds as $index => $id) {
                OtaChannel::query()->whereKey($id)->update(['sort_order' => $index + 1]);
            }

            activity()
                ->causedBy($admin)
                ->event('ota_channel.reordered')
                ->withProperties(['order' => $orderedIds])
                ->log('OTA channels reordered');

            return true;
        });
    }
}
