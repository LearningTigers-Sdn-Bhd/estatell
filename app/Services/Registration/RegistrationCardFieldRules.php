<?php

namespace App\Services\Registration;

use App\Enums\FieldVisibility;
use App\Enums\RegistrationCardField;
use App\Models\Property;

/**
 * Which visibilities a property may give a registration card field.
 */
class RegistrationCardFieldRules
{
    /** True while an enabled tax only applies to foreign guests, so the card must ask for nationality. */
    public function nationalityIsNeededForTax(Property $property): bool
    {
        return $property->taxes()
            ->where('enabled', true)
            ->where('foreign_guests_only', true)
            ->exists();
    }

    /**
     * The visibilities the property may choose for the field right now.
     *
     * @return list<FieldVisibility>
     */
    public function allowedFor(Property $property, RegistrationCardField $field): array
    {
        $allowed = $field->allowedVisibilities();

        if ($field === RegistrationCardField::Nationality && $this->nationalityIsNeededForTax($property)) {
            return array_values(array_filter($allowed, fn (FieldVisibility $visibility): bool => $visibility !== FieldVisibility::Hidden));
        }

        return $allowed;
    }

    /** Why the field cannot take every value, or null when nothing is held back. */
    public function noteFor(Property $property, RegistrationCardField $field): ?string
    {
        return match (true) {
            $field === RegistrationCardField::FullName => __('Every card needs the guest name.'),
            $field === RegistrationCardField::MarketingConsent => __('Consent is always the guest\'s choice.'),
            $field === RegistrationCardField::Nationality && $this->nationalityIsNeededForTax($property) => __('A tax for foreign guests only is on, so nationality stays on the card.'),
            default => null,
        };
    }
}
