<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\UpdatePlatformSettings;
use App\Data\Platform\PlatformSettingsData;
use App\Enums\CommissionService;
use App\Enums\DormStrategy;
use App\Enums\MediaDisk;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Services\Commissions\CommissionRates;
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

    /**
     * The switches for the experimental AI assistant places. See docs/34-ai-assistant/01-overview.md#experimental-places.
     */
    public function ai(PlatformSettings $platformSettings): Response
    {
        return $this->renderTab($platformSettings, 'ai');
    }

    /**
     * The commission rates. See docs/30-commission-fees/05-platform-pages.md#commission-rates.
     */
    public function commissions(PlatformSettings $platformSettings, CommissionRates $commissionRates): Response
    {
        return $this->renderTab($platformSettings, 'commissions', [
            'commissionRates' => $commissionRates->all(),
            'commissionServices' => CommissionService::options(),
        ]);
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

    /**
     * @param  array<string, mixed>  $props
     */
    private function renderTab(PlatformSettings $platformSettings, string $tab, array $props = []): Response
    {
        $fields = match ($tab) {
            'channels' => ['defaultDormStrategy'],
            'storage' => ['mediaDisk'],
            'ai' => ['aiBookingWebsiteEnabled', 'aiStaffHelperEnabled'],
            'commissions' => [],
            default => ['requireGoLiveApproval'],
        };

        return Inertia::render('admin/settings/edit', [
            'activeTab' => $tab,
            'settings' => $fields === [] ? [] : PlatformSettingsData::fromModel($platformSettings->current())->only(...$fields),
            ...match ($tab) {
                'channels' => ['dormStrategies' => DormStrategy::options()],
                'storage' => ['mediaDisks' => MediaDisk::options()],
                default => [],
            },
            ...$props,
        ]);
    }

    public function updateAi(UpdatePlatformSettingsRequest $request, UpdatePlatformSettings $updatePlatformSettings): RedirectResponse
    {
        return $this->save($request, $updatePlatformSettings, 'admin.settings.ai');
    }

    private function save(UpdatePlatformSettingsRequest $request, UpdatePlatformSettings $updatePlatformSettings, string $route): RedirectResponse
    {
        $updatePlatformSettings($request->user(), $request->toPlatformSettingsData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Platform settings saved.')]);

        return to_route($route);
    }
}
