<?php

namespace App\Actions\Property;

use App\Actions\Catalogues\SeedChargeItems;
use App\Actions\Catalogues\SeedDiscounts;
use App\Actions\Catalogues\SeedPaymentMethods;
use App\Actions\Catalogues\SeedRoomRevenuePolicies;
use App\Actions\Catalogues\SeedTaxes;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Actions\Meals\SeedMealPeriods;
use App\Actions\Rates\SeedRatePlans;
use App\Actions\Registration\SeedRegistrationCardFields;
use App\Actions\Registration\SeedRegistrationTerms;
use App\Actions\Reservations\SeedBookingSources;
use App\Actions\Staff\SeedDefaultRoles;
use App\Enums\MissedArrivalPolicy;
use App\Enums\OverdueDeparturePolicy;
use App\Enums\PropertyType;
use App\Enums\RegistrationCardScope;
use App\Enums\WeekendNights;
use App\Models\Property;
use App\Services\Property\CountryProfile;
use App\Services\Property\CountryProfiles;
use Illuminate\Support\Facades\DB;

class SeedPropertyDefaults
{
    public function __construct(
        private SeedDefaultRoles $seedDefaultRoles,
        private SeedTransactionCodes $seedTransactionCodes,
        private SeedChargeItems $seedChargeItems,
        private SeedPaymentMethods $seedPaymentMethods,
        private SeedTaxes $seedTaxes,
        private SeedDiscounts $seedDiscounts,
        private SeedRoomRevenuePolicies $seedRoomRevenuePolicies,
        private SeedMealPeriods $seedMealPeriods,
        private SeedAgeGroups $seedAgeGroups,
        private SeedRatePlans $seedRatePlans,
        private SeedRegistrationCardFields $seedRegistrationCardFields,
        private SeedRegistrationTerms $seedRegistrationTerms,
        private SeedBookingSources $seedBookingSources,
    ) {}

    /**
     * Seed the defaults that are available for a property's type and country.
     */
    public function __invoke(Property $property, PropertyType $type, string $countryCode): void
    {
        $profile = CountryProfiles::for($countryCode);
        $settings = $this->settingsFor($type, $profile);

        DB::transaction(function () use ($property, $settings, $profile, $type): void {
            ($this->seedDefaultRoles)($property);
            $property->settings()->firstOrCreate([], $settings);
            $property->printSetting()->firstOrCreate();
            ($this->seedTransactionCodes)($property, $profile);
            ($this->seedChargeItems)($property);
            ($this->seedPaymentMethods)($property, $profile);
            ($this->seedTaxes)($property, $profile);
            ($this->seedDiscounts)($property);
            ($this->seedRoomRevenuePolicies)($property);
            ($this->seedMealPeriods)($property);
            ($this->seedAgeGroups)($property);
            ($this->seedRatePlans)($property);
            ($this->seedRegistrationCardFields)($property);
            ($this->seedRegistrationTerms)($property, $type, $profile);
            ($this->seedBookingSources)($property);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsFor(PropertyType $type, CountryProfile $profile): array
    {
        return match ($type) {
            PropertyType::Hotel,
            PropertyType::Hostel,
            PropertyType::Resort,
            PropertyType::ServicedApartment,
            PropertyType::Homestay => [
                'currency' => $profile->currency(),
                'time_zone' => $profile->timeZone(),
                'default_locale' => $profile->locales()[0],
                'check_in_time' => '15:00',
                'check_out_time' => '11:00',
                'arrival_grace_minutes' => 0,
                'auto_assign_rooms' => false,
                'audit_time' => '02:00',
                'auto_audit_enabled' => false,
                'missed_arrival_policy' => MissedArrivalPolicy::KeepAsLateArrival,
                'overdue_departure_policy' => OverdueDeparturePolicy::Block,
                'registration_card_scope' => RegistrationCardScope::PrimaryOnly,
                'card_required_for_check_in' => false,
                'id_document_retention_days' => null,
                'security_deposit_amount' => '0.00',
                'allow_support_access' => true,
                'allow_support_money_corrections' => true,
                'weekend_nights' => WeekendNights::FriSat,
            ],
        };
    }
}
