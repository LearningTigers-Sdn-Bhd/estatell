<?php

namespace App\Actions\Property;

use App\Enums\OnboardingStep;
use App\Enums\StayDiscountType;
use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Models\User;
use App\Services\Property\CountryChangeCounts;
use App\Services\Property\CountryProfile;
use App\Services\Property\CountryProfiles;
use App\Services\Property\SettingsLock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ChangePropertyCountry
{
    public function __construct(
        private SeedPropertyDefaults $seedPropertyDefaults,
        private SettingsLock $settingsLock,
        private CountryChangeCounts $countryChangeCounts,
    ) {}

    /**
     * Move a property to another country. Two stages, one transaction:
     *
     * - Setup not started (every other onboarding section Not started): the untouched presets the new profile does not
     *   have are removed (taxes, payment methods, system transaction codes, terms) and the new profile is seeded.
     * - Setup started: the country-bound data is reset (see docs/02-property/03-seed-property-defaults.md#changing-country).
     *   Type, name, address, room types, rooms, rate plans, roles, staff, invitations, check-in and check-out times and
     *   registration card fields stay. The reset counts go to the activity log.
     *
     * Returns false, without writing, when the property has no settings row yet, is live, is not in onboarding, or has a
     * go-live request pending. Currency and time zone come from the new profile unless the profile leaves them open and
     * a value is given.
     */
    public function __invoke(User $actor, Property $property, string $countryCode, ?string $currency = null, ?string $timeZone = null): bool
    {
        $countryCode = mb_strtoupper($countryCode);

        return DB::transaction(function () use ($actor, $property, $countryCode, $currency, $timeZone): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $settings = $property->settings;

            if ($settings === null || ! $this->settingsLock->canChangeCountry($property)) {
                return false;
            }

            $profile = CountryProfiles::for($countryCode);
            $previous = $property->country_code;
            $setupStarted = $this->settingsLock->setupStarted($property);
            $reset = $setupStarted ? $this->countryChangeCounts->forProperty($property) : null;

            if ($setupStarted) {
                $this->resetCountryBoundData($property, $profile);
            } else {
                $this->removePresetsOutside($property, $countryCode);
            }

            $property->forceFill(['country_code' => $countryCode])->save();
            $settings->update(array_merge([
                'currency' => $profile->hasFixedRegion() ? $profile->currency() : ($currency ?? $profile->currency()),
                'time_zone' => $profile->hasFixedRegion() ? $profile->timeZone() : ($timeZone ?? $profile->timeZone()),
                'default_locale' => $profile->locales()[0],
            ], $setupStarted ? ['security_deposit_amount' => '0.00'] : []));

            ($this->seedPropertyDefaults)($property, $property->type, $countryCode);

            if ($previous !== $countryCode) {
                activity()
                    ->performedOn($property)
                    ->causedBy($actor)
                    ->event('property.country-changed')
                    ->withProperties([
                        'property_id' => $property->id,
                        'from' => $previous,
                        'to' => $countryCode,
                        'setup_started' => $setupStarted,
                        'reset' => $reset?->toArray(),
                    ])
                    ->log('Property country changed');
            }

            return true;
        });
    }

    /**
     * Delete the untouched presets that the new country's profile does not have, and the terms document, which is
     * seeded again in the new profile's languages.
     */
    private function removePresetsOutside(Property $property, string $countryCode): void
    {
        $profile = CountryProfiles::for($countryCode);

        $keep = static fn (array $presets): array => array_map(
            fn (array $preset): string => $preset['systemCode']->value,
            $presets,
        );
        $taxKeys = $keep($profile->taxPresets());
        $paymentKeys = $keep($profile->paymentMethodPresets());

        $taxes = $property->taxes()
            ->whereHas('transactionCode', fn ($query) => $query->whereNotNull('system_key')->whereNotIn('system_key', $taxKeys))
            ->get();
        $taxIds = $taxes->modelKeys();

        DB::table('print_setting_taxes')->whereIn('tax_id', $taxIds)->delete();
        DB::table('tax_transaction_code')->whereIn('tax_id', $taxIds)->delete();
        $property->taxes()->whereKey($taxIds)->delete();

        $property->paymentMethods()
            ->whereHas('transactionCode', fn ($query) => $query->whereNotNull('system_key')->whereNotIn('system_key', $paymentKeys))
            ->delete();

        $this->removeSystemCodesOutside($property, $profile);
        $this->removeTerms($property);
    }

    /**
     * Reset everything that depends on the country or its currency, and keep the rest. Taxes, payment methods, charge
     * items, discounts and the amounts of the rates are removed or cleared, then the new profile is seeded again. Rate
     * plans, room types and rooms stay. Transaction codes shared by both profiles stay, and the others go.
     */
    private function resetCountryBoundData(Property $property, CountryProfile $profile): void
    {
        $taxIds = $property->taxes()->pluck('id')->all();
        $codeIds = array_merge(
            $property->taxes()->pluck('transaction_code_id')->all(),
            $property->paymentMethods()->pluck('transaction_code_id')->all(),
            $property->chargeItems()->pluck('transaction_code_id')->all(),
            $property->discounts()->pluck('transaction_code_id')->all(),
        );
        $planIds = DB::table('rate_plans')->where('property_id', $property->id)->select('id');

        DB::table('print_setting_taxes')->whereIn('tax_id', $taxIds)->delete();
        DB::table('tax_transaction_code')->whereIn('tax_id', $taxIds)->delete();
        $property->taxes()->delete();
        $property->paymentMethods()->delete();

        DB::table('rate_plan_inclusions')->whereIn('rate_plan_id', $planIds)->delete();
        $property->discounts()->delete();
        $property->chargeItems()->delete();

        $this->clearPrices($property, $planIds);

        $property->transactionCodes()->whereIn('id', $codeIds)->where('is_system', false)->delete();
        $this->removeSystemCodesOutside($property, $profile);
        $this->removeTerms($property);

        OnboardingSkip::query()
            ->where('property_id', $property->id)
            ->whereIn('step', array_map(fn (OnboardingStep $step): string => $step->value, CountryChangeCounts::resetSteps()))
            ->delete();
    }

    /**
     * Clear the amounts of the rates: the nightly prices, occupancy, hour and package prices, child prices, plan extra
     * amounts and amount-off stay discounts. Percent stay discounts stay, because they carry no currency.
     */
    private function clearPrices(Property $property, Builder $planIds): void
    {
        $rateIds = DB::table('rates')->whereIn('rate_plan_id', $planIds)->select('id');

        DB::table('rates')->whereIn('rate_plan_id', $planIds)->whereNotNull('amount')->update(['amount' => null]);
        DB::table('rate_occupancy_prices')->whereIn('rate_id', $rateIds)->delete();
        DB::table('rate_hour_prices')->whereIn('rate_id', $rateIds)->delete();
        DB::table('package_prices')->whereIn('rate_plan_id', $planIds)->delete();
        DB::table('rate_plan_child_prices')->whereIn('rate_plan_id', $planIds)->update(['value' => null]);
        DB::table('rate_plan_stay_discounts')->whereIn('rate_plan_id', $planIds)
            ->where('discount_type', StayDiscountType::Amount->value)
            ->delete();
        DB::table('rate_plans')->where('property_id', $property->id)->update([
            'extra_adult_amount' => 0,
            'extra_child_amount' => 0,
            'single_supplement' => 0,
        ]);
    }

    /**
     * Remove the system transaction codes that the new profile does not have. Their rows are removed before this runs.
     */
    private function removeSystemCodesOutside(Property $property, CountryProfile $profile): void
    {
        $codeKeys = array_map(fn ($code): string => $code->value, $profile->transactionCodes());

        $property->transactionCodes()
            ->where('is_system', true)
            ->whereNotIn('system_key', $codeKeys)
            ->delete();
    }

    private function removeTerms(Property $property): void
    {
        foreach ($property->termsDocuments()->get() as $document) {
            $document->delete();
        }
    }
}
