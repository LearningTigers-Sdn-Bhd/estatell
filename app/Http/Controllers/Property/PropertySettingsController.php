<?php

namespace App\Http\Controllers\Property;

use App\Actions\Property\UpdatePropertySettings;
use App\Data\Property\PropertyAgeGroupData;
use App\Data\Property\PropertySettingsData;
use App\Enums\Currency;
use App\Enums\Locale;
use App\Enums\MissedArrivalPolicy;
use App\Enums\NoShowCharge;
use App\Enums\OverdueDeparturePolicy;
use App\Enums\RegistrationCardScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\SaveAgeGroupsRequest;
use App\Http\Requests\Property\UpdatePropertySettingsRequest;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Services\Property\SettingsLock;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PropertySettingsController extends Controller
{
    /**
     * Settings page, General tab.
     */
    public function edit(Property $property, SettingsLock $settingsLock): Response
    {
        return $this->renderTab($property, $settingsLock, 'general', [
            'currencies' => Currency::options(),
            'locales' => Locale::options(),
        ]);
    }

    /**
     * Settings page, Operations tab.
     */
    public function operations(Property $property, SettingsLock $settingsLock): Response
    {
        return $this->renderTab($property, $settingsLock, 'operations', [
            'missedArrivalPolicies' => MissedArrivalPolicy::options(),
            'overdueDeparturePolicies' => OverdueDeparturePolicy::options(),
            'noShowCharges' => NoShowCharge::options(),
        ]);
    }

    /**
     * Settings page, Registration card tab.
     */
    public function registration(Property $property, SettingsLock $settingsLock): Response
    {
        return $this->renderTab($property, $settingsLock, 'registration', [
            'registrationCardScopes' => RegistrationCardScope::options(),
        ]);
    }

    /**
     * Settings page, Support access tab.
     */
    public function support(Property $property, SettingsLock $settingsLock): Response
    {
        return $this->renderTab($property, $settingsLock, 'support');
    }

    /**
     * Settings page, Age groups tab. Saved by `AgeGroupController::update`.
     */
    public function ageGroups(Property $property, SettingsLock $settingsLock): Response
    {
        return $this->renderTab($property, $settingsLock, 'age-groups', [
            'ageGroups' => $property->ageGroups()
                ->ordered()
                ->get()
                ->map(fn (PropertyAgeGroup $ageGroup): PropertyAgeGroupData => PropertyAgeGroupData::fromModel($ageGroup)),
            'maxAge' => SaveAgeGroupsRequest::MAX_AGE,
        ]);
    }

    public function update(UpdatePropertySettingsRequest $request, Property $property, string $section, UpdatePropertySettings $updatePropertySettings): RedirectResponse
    {
        abort_if($property->settings === null, 404);

        $updatePropertySettings($property, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property settings saved.')]);

        return to_route($section === 'general' ? 'settings.edit' : "settings.{$section}", ['property' => $property->code]);
    }

    /**
     * Each tab has its own URL, so the open tab survives a refresh. Only the open tab's options are sent.
     *
     * @param  'general'|'operations'|'registration'|'support'|'age-groups'  $tab
     * @param  array<string, mixed>  $tabProps
     */
    private function renderTab(Property $property, SettingsLock $settingsLock, string $tab, array $tabProps = []): Response
    {
        $settings = $property->settings;

        return Inertia::render('settings/edit', [
            'settings' => $settings !== null
                ? PropertySettingsData::fromModel($settings, $settingsLock->locksCurrencyAndTimeZone($property))
                : null,
            'tab' => $tab,
            ...$tabProps,
        ]);
    }
}
