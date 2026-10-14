<?php

namespace App\Actions\Platform;

use App\Data\Platform\PlatformSettingsInputData;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UpdatePlatformSettings
{
    /**
     * Save the platform settings and log what changed.
     */
    public function __invoke(User $admin, PlatformSettingsInputData $data): PlatformSetting
    {
        $settings = DB::transaction(function () use ($admin, $data): PlatformSetting {
            $settings = PlatformSetting::query()->lockForUpdate()->findOrFail(1);
            $settings->fill([
                'require_go_live_approval' => $data->requireGoLiveApproval,
                'default_dorm_strategy' => $data->defaultDormStrategy,
                'media_disk' => $data->mediaDisk,
            ]);

            $changed = Arr::except($settings->getDirty(), ['updated_at']);

            if ($changed === []) {
                return $settings;
            }

            $old = Arr::only($settings->getRawOriginal(), array_keys($changed));

            $settings->save();

            activity()
                ->performedOn($settings)
                ->causedBy($admin)
                ->event('updated')
                ->withChanges(['attributes' => $changed, 'old' => $old])
                ->log('Platform settings updated');

            return $settings;
        });

        Cache::forget(PlatformSettings::CACHE_KEY);

        return $settings;
    }
}
