<?php

namespace App\Actions\Property;

use App\Models\Property;
use App\Models\User;
use App\Services\Property\CountryProfiles;
use App\Services\Property\SettingsLock;
use Illuminate\Support\Facades\DB;

class ChangePropertyCountry
{
    public function __construct(
        private SeedPropertyDefaults $seedPropertyDefaults,
        private SettingsLock $settingsLock,
    ) {}

    /**
     * Move a property to another country while setup has not started: every other onboarding section is Not started
     * and the property is not live. The seeded presets are still untouched then, so the ones the new profile does not
     * have are removed (taxes, payment methods, system transaction codes, terms) and the new profile is seeded.
     * Currency and time zone come from the new profile unless the profile leaves them open and a value is given.
     *
     * Returns false, without writing, when the property is locked or has no settings row yet (the first Basics save
     * seeds it). Room types, rooms and team stay. After setup started, the later Change country item takes over.
     */
    public function __invoke(User $actor, Property $property, string $countryCode, ?string $currency = null, ?string $timeZone = null): bool
    {
        $countryCode = mb_strtoupper($countryCode);

        return DB::transaction(function () use ($actor, $property, $countryCode, $currency, $timeZone): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $settings = $property->settings;

            if ($settings === null || $this->settingsLock->locksCountry($property)) {
                return false;
            }

            $profile = CountryProfiles::for($countryCode);
            $previous = $property->country_code;

            $this->removePresetsOutside($property, $countryCode);

            $property->forceFill(['country_code' => $countryCode])->save();
            $settings->update([
                'currency' => $profile->hasFixedRegion() ? $profile->currency() : ($currency ?? $profile->currency()),
                'time_zone' => $profile->hasFixedRegion() ? $profile->timeZone() : ($timeZone ?? $profile->timeZone()),
                'default_locale' => $profile->locales()[0],
            ]);

            ($this->seedPropertyDefaults)($property, $property->type, $countryCode);

            if ($previous !== $countryCode) {
                activity()
                    ->performedOn($property)
                    ->causedBy($actor)
                    ->event('property.country-changed')
                    ->withProperties(['property_id' => $property->id, 'from' => $previous, 'to' => $countryCode])
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
        $codeKeys = array_map(fn ($code): string => $code->value, $profile->transactionCodes());

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

        $property->transactionCodes()
            ->where('is_system', true)
            ->whereNotIn('system_key', $codeKeys)
            ->delete();

        foreach ($property->termsDocuments()->get() as $document) {
            $document->delete();
        }
    }
}
