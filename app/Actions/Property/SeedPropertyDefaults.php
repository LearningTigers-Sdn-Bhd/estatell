<?php

namespace App\Actions\Property;

use App\Actions\Catalogues\SeedTaxes;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Actions\Staff\SeedDefaultRoles;
use App\Enums\Currency;
use App\Enums\Locale;
use App\Enums\MissedArrivalPolicy;
use App\Enums\NoShowCharge;
use App\Enums\OverdueDeparturePolicy;
use App\Enums\PropertyType;
use App\Enums\RegistrationCardScope;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SeedPropertyDefaults
{
    public function __construct(
        private SeedDefaultRoles $seedDefaultRoles,
        private SeedTransactionCodes $seedTransactionCodes,
        private SeedTaxes $seedTaxes,
    ) {}

    /**
     * Seed the defaults that are available for a property's type and country.
     */
    public function __invoke(Property $property, PropertyType $type, string $countryCode): void
    {
        $settings = $this->settingsFor($type, $countryCode);

        DB::transaction(function () use ($property, $settings, $countryCode): void {
            ($this->seedDefaultRoles)($property);
            $property->settings()->firstOrCreate([], $settings);
            ($this->seedTransactionCodes)($property, $countryCode);
            ($this->seedTaxes)($property, $countryCode);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsFor(PropertyType $type, string $countryCode): array
    {
        if ($countryCode !== 'MY') {
            throw new InvalidArgumentException("Property defaults are not available for country [{$countryCode}].");
        }

        return match ($type) {
            PropertyType::Hotel,
            PropertyType::Hostel,
            PropertyType::Resort,
            PropertyType::ServicedApartment,
            PropertyType::Homestay => [
                'currency' => Currency::Myr,
                'time_zone' => 'Asia/Kuala_Lumpur',
                'default_locale' => Locale::English,
                'check_in_time' => '15:00',
                'check_out_time' => '11:00',
                'arrival_grace_minutes' => 0,
                'auto_assign_rooms' => false,
                'audit_time' => '02:00',
                'auto_audit_enabled' => false,
                'missed_arrival_policy' => MissedArrivalPolicy::KeepAsLateArrival,
                'overdue_departure_policy' => OverdueDeparturePolicy::Block,
                'no_show_charge' => NoShowCharge::None,
                'registration_card_scope' => RegistrationCardScope::PrimaryOnly,
                'card_required_for_check_in' => false,
                'id_document_retention_days' => null,
                'security_deposit_amount' => '0.00',
                'allow_support_access' => true,
            ],
        };
    }
}
