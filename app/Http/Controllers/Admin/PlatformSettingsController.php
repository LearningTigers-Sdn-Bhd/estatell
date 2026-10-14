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
        return Inertia::render('admin/settings/edit', [
            'settings' => PlatformSettingsData::fromModel($platformSettings->current()),
            'dormStrategies' => DormStrategy::options(),
            'mediaDisks' => MediaDisk::options(),
        ]);
    }

    /**
     * Save the platform settings.
     */
    public function update(UpdatePlatformSettingsRequest $request, UpdatePlatformSettings $updatePlatformSettings): RedirectResponse
    {
        $updatePlatformSettings($request->user(), $request->toPlatformSettingsData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Platform settings saved.')]);

        return to_route('admin.settings.edit');
    }
}
