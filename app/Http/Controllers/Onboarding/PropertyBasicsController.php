<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\SavePropertyBasics;
use App\Data\Property\CountryProfileData;
use App\Data\Property\PropertyBasicsData;
use App\Enums\OnboardingStep;
use App\Enums\PropertyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SavePropertyBasicsRequest;
use App\Http\Requests\Property\SavePropertyBusinessRequest;
use App\Models\Property;
use App\Services\Guests\CountryOptions;
use App\Services\Property\CountryProfiles;
use App\Services\Property\CountryTimeZones;
use App\Services\Property\CurrencyOptions;
use App\Services\Property\MalaysianStates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class PropertyBasicsController extends Controller
{
    public function edit(Property $property, CountryOptions $countryOptions): Response
    {
        $countries = $countryOptions->optionsWithMalaysiaFirst();

        return Inertia::render('onboarding/basics', [
            'basics' => PropertyBasicsData::fromModel($property),
            'propertyTypes' => PropertyType::options(),
            'countries' => $countries,
            'currencies' => CurrencyOptions::options(),
            'malaysianStates' => MalaysianStates::options(),
            'regions' => Cache::remember('onboarding.basics-regions', now()->addDay(), fn (): array => $this->regions($countries)),
            'profiles' => [
                'MY' => CountryProfileData::fromProfile(CountryProfiles::for('MY')),
                'default' => CountryProfileData::fromProfile(CountryProfiles::for('ZZ')),
            ],
            'maxLicences' => SavePropertyBusinessRequest::MAX_LICENCES,
        ]);
    }

    public function update(SavePropertyBasicsRequest $request, Property $property, SavePropertyBasics $savePropertyBasics): RedirectResponse
    {
        $saved = $savePropertyBasics($request->user(), $property, $request->basics(), $request->business(), $request->licences());

        if (! $saved) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Property basics could not be saved. Reload the page and try again.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property basics saved.')]);

        $next = OnboardingStep::Basics->nextListed();

        return $next === null
            ? back()
            : to_route($next->routeName(), ['property' => $property->code]);
    }

    /**
     * The currency and time zones each country prefills, so choosing a country needs no request.
     *
     * @param  list<array{value: string, label: string}>  $countries
     * @return array<string, array{currency: string, timeZones: list<string>}>
     */
    private function regions(array $countries): array
    {
        $regions = [];

        foreach ($countries as $country) {
            $profile = CountryProfiles::for($country['value']);
            $regions[$country['value']] = [
                'currency' => $profile->suggestedCurrency() ?? '',
                'timeZones' => CountryTimeZones::for($country['value']),
            ];
        }

        return $regions;
    }
}
