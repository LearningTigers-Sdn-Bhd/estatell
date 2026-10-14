<?php

namespace App\Http\Controllers\Property;

use App\Actions\Property\SavePropertyBusiness;
use App\Actions\Property\UpdatePropertyProfile;
use App\Actions\Property\UpdatePropertySettings;
use App\Data\Meals\MealPeriodData;
use App\Data\Property\PropertyAgeGroupData;
use App\Data\Property\PropertyBusinessData;
use App\Data\Property\PropertyProfileData;
use App\Data\Property\PropertySettingsData;
use App\Enums\Locale;
use App\Enums\MissedArrivalPolicy;
use App\Enums\OverdueDeparturePolicy;
use App\Enums\WeekendNights;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meals\SaveMealPeriodsRequest;
use App\Http\Requests\Property\SaveAgeGroupsRequest;
use App\Http\Requests\Property\SavePropertyBusinessRequest;
use App\Http\Requests\Property\UpdatePropertyProfileRequest;
use App\Http\Requests\Property\UpdatePropertySettingsRequest;
use App\Models\MealPeriod;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Services\Property\CurrencyOptions;
use App\Services\Property\SettingsLock;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PropertySettingsController extends Controller
{
    /**
     * Settings page, Profile tab: the property identity and logo. The sidebar opens this tab.
     */
    public function profile(Property $property, SettingsLock $settingsLock): Response
    {
        return $this->renderTab($property, $settingsLock, 'profile', [
            'profile' => PropertyProfileData::fromModel($property),
        ]);
    }

    public function updateProfile(UpdatePropertyProfileRequest $request, Property $property, UpdatePropertyProfile $updatePropertyProfile): RedirectResponse
    {
        abort_if($property->settings === null, 404);

        $updatePropertyProfile($request->user(), $property, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property profile saved.')]);

        return to_route('settings.profile', ['property' => $property->code]);
    }

    /**
     * Settings page, Business licence tab: the registered name, registration number, TIN and licences.
     */
    public function business(Property $property, SettingsLock $settingsLock): Response
    {
        return $this->renderTab($property, $settingsLock, 'business', [
            'business' => PropertyBusinessData::fromModel($property),
            'maxLicences' => SavePropertyBusinessRequest::MAX_LICENCES,
        ]);
    }

    public function updateBusiness(SavePropertyBusinessRequest $request, Property $property, SavePropertyBusiness $savePropertyBusiness): RedirectResponse
    {
        abort_if($property->settings === null, 404);

        if (! $savePropertyBusiness($request->user(), $property, $request->business(), $request->licences())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The licences changed. Reload the page and try again.')]);

            return to_route('settings.business', ['property' => $property->code]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Business details saved.')]);

        return to_route('settings.business', ['property' => $property->code]);
    }

    /**
     * Settings page, General tab.
     */
    public function edit(Property $property, SettingsLock $settingsLock): Response
    {
        return $this->renderTab($property, $settingsLock, 'general', [
            'currencies' => CurrencyOptions::options(),
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
            'weekendNightsOptions' => WeekendNights::options(),
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

    /**
     * Settings page, Meal periods tab. Saved by `MealPeriodController::update`.
     */
    public function mealPeriods(Property $property, SettingsLock $settingsLock): Response
    {
        return $this->renderTab($property, $settingsLock, 'meal-periods', [
            'mealPeriods' => $property->mealPeriods()
                ->ordered()
                ->get()
                ->map(fn (MealPeriod $mealPeriod): MealPeriodData => MealPeriodData::fromModel($mealPeriod)),
            'maxMealPeriods' => SaveMealPeriodsRequest::MAX_PERIODS,
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
     * @param  'profile'|'business'|'general'|'operations'|'support'|'age-groups'|'meal-periods'  $tab
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
