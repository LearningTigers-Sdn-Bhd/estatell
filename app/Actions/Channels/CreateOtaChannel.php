<?php

namespace App\Actions\Channels;

use App\Models\OtaChannel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateOtaChannel
{
    /**
     * Add a channel at the end of the list. The code is the lowercase name with each run of other characters
     * turned into an underscore ("Trip.com" gives trip_com). It never changes after that. Returns null when the name gives an empty code or a code that is already taken.
     */
    public function __invoke(User $admin, string $name): ?OtaChannel
    {
        return DB::transaction(function () use ($admin, $name): ?OtaChannel {
            $code = Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

            if ($code === '' || OtaChannel::query()->where('code', $code)->exists()) {
                return null;
            }

            $channel = OtaChannel::query()->create([
                'code' => $code,
                'name' => $name,
                'sort_order' => ((int) OtaChannel::query()->max('sort_order')) + 1,
                'is_active' => true,
                'is_other' => false,
            ]);

            activity()
                ->performedOn($channel)
                ->causedBy($admin)
                ->event('ota_channel.created')
                ->withProperties(['code' => $code, 'name' => $name])
                ->log('OTA channel added');

            return $channel;
        });
    }
}
