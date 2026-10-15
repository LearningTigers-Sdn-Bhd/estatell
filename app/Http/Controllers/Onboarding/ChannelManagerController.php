<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\SaveOtaCredentials;
use App\Data\Onboarding\OnboardingOtaChannelData;
use App\Data\Onboarding\OnboardingOtaCredentialRowData;
use App\Enums\OnboardingStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SaveOtaCredentialsRequest;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\PropertyOtaCredential;
use App\Services\Onboarding\OnboardingProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChannelManagerController extends Controller
{
    /**
     * The saved OTA lists, without passwords, and the channels an owner can pick. A locked step opens as a preview.
     */
    public function edit(Request $request, Property $property, OnboardingProgress $progress): Response
    {
        $blockingStep = $progress->blockingStep($property, OnboardingStep::ChannelManager);

        return Inertia::render('onboarding/channel-manager', [
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
            'maxRows' => SaveOtaCredentialsRequest::MAX_ROWS,
            'locked' => $blockingStep !== null,
            'blockedBy' => $blockingStep === null ? null : [
                'label' => $blockingStep->label(),
                'url' => $blockingStep->routeName() === null ? null : route($blockingStep->routeName(), ['property' => $property->code], absolute: false),
            ],
        ]);
    }

    public function update(SaveOtaCredentialsRequest $request, Property $property, SaveOtaCredentials $saveOtaCredentials): RedirectResponse
    {
        $rows = $request->rows();

        $saveOtaCredentials($property, $request->user(), $rows);

        Inertia::flash('toast', ['type' => 'success', 'message' => $rows === []
            ? __('OTA lists skipped.')
            : __('OTA lists saved.')]);

        $next = OnboardingStep::ChannelManager->nextListed();

        return $next === null
            ? back()
            : to_route($next->routeName(), ['property' => $property->code]);
    }
}
