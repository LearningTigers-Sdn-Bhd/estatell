<?php

namespace App\Actions\Onboarding\Wastays;

use App\Actions\Onboarding\SavePropertyBasics;
use App\Actions\Property\UpdatePropertySettings;
use App\Models\Property;
use App\Models\User;

/**
 * Fills the property details a go-live needs from the wastays hotel row: address, city, phone, email, registration
 * number and TIN, only where the property field is empty. A property with no settings row yet takes the Basics save,
 * which seeds its defaults. The check-in and check-out times are not in the export, so the console passes them in.
 */
class ImportWastaysDetails
{
    public function __construct(
        private SavePropertyBasics $savePropertyBasics,
        private UpdatePropertySettings $updatePropertySettings,
    ) {}

    /**
     * @param  array<string, string|null>  $hotel  the `hotels.csv` row
     */
    public function __invoke(Property $property, User $actor, array $hotel, ?string $checkIn, ?string $checkOut, WastaysSetupSummary $summary): void
    {
        $values = array_filter([
            'address_line1' => $hotel['address'] ?? null,
            'city' => $hotel['city'] ?? null,
            'contact_phone' => $hotel['contact_phone'] ?? null,
            'contact_email' => $hotel['contact_email'] ?? null,
        ], fn (?string $value): bool => filled($value));
        $business = array_filter([
            'registration_number' => $hotel['ssm_number'] ?? null,
            'tin' => $hotel['tin'] ?? null,
        ], fn (?string $value): bool => filled($value));

        $empty = fn (array $fields): array => array_filter($fields, fn (string $value, string $key): bool => blank($property->{$key}), ARRAY_FILTER_USE_BOTH);
        $values = array_map(trim(...), $empty($values));
        $business = array_map(trim(...), $empty($business));

        if ($property->settings()->doesntExist()) {
            $this->saveBasics($property, $actor, $values, $business, $summary);
        } elseif ($values !== [] || $business !== []) {
            $property->fill([...$values, ...$business])->save();
            $summary->count('Property details', 'created');
        } else {
            $summary->count('Property details', 'linked');
        }

        if ($checkIn !== null && $checkOut !== null) {
            ($this->updatePropertySettings)($property->refresh(), ['check_in_time' => $checkIn, 'check_out_time' => $checkOut]);
            $summary->count('Check-in times', 'created');
        }
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, string>  $business
     */
    private function saveBasics(Property $property, User $actor, array $values, array $business, WastaysSetupSummary $summary): void
    {
        $address = [...$values, ...array_filter(['address_line1' => $property->address_line1, 'city' => $property->city], filled(...))];

        if (blank($address['address_line1'] ?? null) || blank($address['city'] ?? null)) {
            $summary->skip('Property details', 'the hotel has no address or city, so Basics is left for the owner');

            return;
        }

        ($this->savePropertyBasics)($actor, $property, [
            'type' => $property->type,
            'name' => $property->name,
            ...$address,
        ], [
            'legal_name' => $property->legal_name,
            'registration_number' => $business['registration_number'] ?? $property->registration_number,
            'tin' => $business['tin'] ?? $property->tin,
        ], []);

        $property->refresh();
        $summary->count('Property details', 'created');
    }
}
