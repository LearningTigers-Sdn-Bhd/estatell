<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\UpdatePlatformSettings;
use App\Data\Platform\PlatformSettingsData;
use App\Enums\DormStrategy;
use App\Enums\MediaDisk;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Services\Platform\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PlatformSettingsController extends Controller
{
    /**
     * Show the platform settings form.
     */
    public function edit(PlatformSettings $platformSettings): Response
    {
        return $this->renderTab($platformSettings, 'general');
    }

    public function channels(PlatformSettings $platformSettings): Response
    {
        return $this->renderTab($platformSettings, 'channels');
    }

    public function storage(PlatformSettings $platformSettings): Response
    {
        return $this->renderTab($platformSettings, 'storage');
    }

    public function update(UpdatePlatformSettingsRequest $request, UpdatePlatformSettings $updatePlatformSettings): RedirectResponse
    {
        return $this->save($request, $updatePlatformSettings, 'admin.settings.edit');
    }

    public function updateChannels(UpdatePlatformSettingsRequest $request, UpdatePlatformSettings $updatePlatformSettings): RedirectResponse
    {
        return $this->save($request, $updatePlatformSettings, 'admin.settings.channels');
    }

    public function updateStorage(UpdatePlatformSettingsRequest $request, UpdatePlatformSettings $updatePlatformSettings): RedirectResponse
    {
        return $this->save($request, $updatePlatformSettings, 'admin.settings.storage');
    }

    private function renderTab(PlatformSettings $platformSettings, string $tab): Response
    {
        $field = match ($tab) {
            'channels' => 'defaultDormStrategy',
            'storage' => 'mediaDisk',
            default => 'requireGoLiveApproval',
        };

        return Inertia::render('admin/settings/edit', [
            'activeTab' => $tab,
            'settings' => PlatformSettingsData::fromModel($platformSettings->current())->only($field),
            ...match ($tab) {
                'channels' => ['dormStrategies' => DormStrategy::options()],
                'storage' => ['mediaDisks' => MediaDisk::options()],
                default => [],
            },
        ]);
    }

    private function save(UpdatePlatformSettingsRequest $request, UpdatePlatformSettings $updatePlatformSettings, string $route): RedirectResponse
    {
        $updatePlatformSettings($request->user(), $request->toPlatformSettingsData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Platform settings saved.')]);

        return to_route($route);
    }
}
