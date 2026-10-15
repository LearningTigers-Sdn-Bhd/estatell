<?php

namespace App\Http\Controllers\Property;

use App\Actions\Onboarding\SaveOtaCredentials;
use App\Data\Onboarding\OnboardingOtaChannelData;
use App\Data\Onboarding\OnboardingOtaCredentialRowData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\SaveOtaListsRequest;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\PropertyOtaCredential;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OtaListSettingsController extends Controller
{
    /**
     * The saved OTA lists, without passwords, and the channels an owner can pick. A saved row on a channel that is no
     * longer active still shows its channel name.
     */
    public function edit(Property $property): Response
    {
        return Inertia::render('settings/ota-lists', [
            'savedRows' => $property->otaCredentials()
                ->with('channel')
                ->orderBy('id')
                ->get()
                ->map(fn (PropertyOtaCredential $credential): OnboardingOtaCredentialRowData => OnboardingOtaCredentialRowData::fromModel($credential)),
            'channels' => OtaChannel::query()
                ->active()
                ->ordered()
                ->get()
                ->map(fn (OtaChannel $channel): OnboardingOtaChannelData => OnboardingOtaChannelData::fromModel($channel)),
            'maxRows' => SaveOtaListsRequest::MAX_ROWS,
        ]);
    }

    public function update(SaveOtaListsRequest $request, Property $property, SaveOtaCredentials $saveOtaCredentials): RedirectResponse
    {
        $saveOtaCredentials($property, $request->user(), $request->rows(), fromSettings: true);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('OTA lists saved.')]);

        return to_route('settings.ota-lists.edit', $property);
    }
}
