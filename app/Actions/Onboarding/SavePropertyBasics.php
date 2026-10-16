<?php

namespace App\Actions\Onboarding;

use App\Actions\Property\ChangePropertyCountry;
use App\Actions\Property\SavePropertyBusiness;
use App\Actions\Property\SeedPropertyDefaults;
use App\Models\Property;
use App\Models\User;
use App\Services\Property\CountryProfiles;
use Illuminate\Support\Facades\DB;
use Throwable;

class SavePropertyBasics
{
    public function __construct(
        private SeedPropertyDefaults $seedPropertyDefaults,
        private ChangePropertyCountry $changePropertyCountry,
        private SavePropertyBusiness $savePropertyBusiness,
    ) {}

    /**
     * Save the property identity and business details. The first save seeds the defaults of the chosen country. A later
     * save only updates type, name, address and business details, and the country, currency and time zone when the
     * request sends them (see SavePropertyBasicsRequest). A changed country goes through ChangePropertyCountry, which
     * refuses a live or pending property.
     *
     * Returns false, without writing, when a licence is not one of the property's or the country change is refused.
     *
     * @param  array<string, mixed>  $attributes  Validated Basics fields. `country_code`, `currency` and `time_zone` are absent when they are locked.
     * @param  array{legal_name: string|null, registration_number: string|null, tin: string|null}  $business
     * @param  list<array{id: int|null, issuer: string, number: string, expires_on: string|null, show_on_documents: bool}>  $licences
     */
    public function __invoke(User $actor, Property $property, array $attributes, array $business, array $licences): bool
    {
        $countryCode = $attributes['country_code'] ?? $property->country_code ?? 'MY';
        $currency = $attributes['currency'] ?? null;
        $timeZone = $attributes['time_zone'] ?? null;
        $identity = array_diff_key($attributes, array_flip(['country_code', 'currency', 'time_zone']));
        $isFirstSave = $property->settings()->doesntExist();
        $changesCountry = ! $isFirstSave && isset($attributes['country_code']) && $attributes['country_code'] !== $property->country_code;

        DB::beginTransaction();

        try {
            if (! ($this->savePropertyBusiness)($actor, $property, $business, $licences)) {
                DB::rollBack();

                return false;
            }

            $property->fill($identity);

            if ($isFirstSave) {
                $property->country_code = $countryCode;
            }

            $property->save();

            if ($isFirstSave) {
                ($this->seedPropertyDefaults)($property, $property->type, $countryCode);
                $this->applyRegion($property, $countryCode, $currency, $timeZone);
            } elseif ($changesCountry) {
                if (! ($this->changePropertyCountry)($actor, $property, $countryCode, $currency, $timeZone)) {
                    DB::rollBack();

                    return false;
                }
            } else {
                $this->applyRegion($property, $countryCode, $currency, $timeZone);
            }

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return true;
    }

    /**
     * Currency and time zone outside Malaysia, when the form sent them. Malaysia keeps the profile values.
     */
    private function applyRegion(Property $property, string $countryCode, ?string $currency, ?string $timeZone): void
    {
        if (CountryProfiles::for($countryCode)->hasFixedRegion() || ($currency === null && $timeZone === null)) {
            return;
        }

        $property->settings()->firstOrFail()->update(array_filter([
            'currency' => $currency,
            'time_zone' => $timeZone,
        ]));
    }
}
