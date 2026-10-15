<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\SaveOnboardingCheckInEssentials;
use App\Data\Property\PropertySettingsData;
use App\Enums\OnboardingStep;
use App\Enums\RegistrationCardScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SaveCheckInEssentialsRequest;
use App\Models\Property;
use App\Services\Onboarding\OnboardingProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class CheckInEssentialsController extends Controller
{
    /**
     * The times and the registration card settings. A locked step opens as a preview.
     */
    public function edit(Property $property, OnboardingProgress $progress): Response
    {
        $blockingStep = $progress->blockingStep($property, OnboardingStep::CheckInEssentials);
        $settings = $property->settings;

        return Inertia::render('onboarding/check-in-essentials', [
            'settings' => $settings === null ? null : PropertySettingsData::fromModel($settings, false),
            'times' => $this->times($settings?->check_in_time, $settings?->check_out_time),
            'scopes' => RegistrationCardScope::options(),
            'locked' => $blockingStep !== null,
            'blockedBy' => $blockingStep === null ? null : [
                'label' => $blockingStep->label(),
                'url' => $blockingStep->routeName() === null ? null : route($blockingStep->routeName(), ['property' => $property->code], absolute: false),
            ],
        ]);
    }

    public function update(SaveCheckInEssentialsRequest $request, Property $property, SaveOnboardingCheckInEssentials $saveCheckInEssentials): RedirectResponse
    {
        $saveCheckInEssentials($property, $request->settings());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Check-in essentials saved.')]);

        $next = OnboardingStep::CheckInEssentials->nextListed();

        return $next === null
            ? back()
            : to_route($next->routeName(), ['property' => $property->code]);
    }

    /**
     * Times every 30 minutes, plus a saved time that is not on that step so the menu can show it.
     *
     * @return list<array{value: string, label: string}>
     */
    private function times(?string $checkIn, ?string $checkOut): array
    {
        $values = [];

        for ($minutes = 0; $minutes < 24 * 60; $minutes += 30) {
            $values[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        foreach ([$checkIn, $checkOut] as $saved) {
            if ($saved !== null) {
                $values[] = substr($saved, 0, 5);
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        return array_map(
            fn (string $value): array => ['value' => $value, 'label' => Carbon::createFromFormat('H:i', $value)->format('g:i A')],
            $values,
        );
    }
}
