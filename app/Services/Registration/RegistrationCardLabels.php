<?php

namespace App\Services\Registration;

use App\Enums\Gender;
use App\Enums\IdentityDocumentType;
use App\Enums\Locale;
use App\Enums\RegistrationCardField;

/**
 * The words on the card page, in one language, from `lang/{locale}.json`. Keys are the English text.
 * A word with no translation stays in English.
 */
class RegistrationCardLabels
{
    /** Words that are not field labels. */
    private const array INTERFACE_WORDS = [
        'Registration card', 'Guest details', 'Stay details', 'Prices', 'Terms', 'Language', 'Signature',
        'Clear', 'Done', 'Cancel', 'Sign card', 'Name of signer', 'ID document', 'Use this document', 'Enter a different document',
        'Document type', 'Document number', 'Issuing country', 'Address line 1', 'Address line 2', 'City', 'State',
        'Postal code', 'Country', 'I agree to receive offers', 'Please sign in the box.', 'Street and number', 'Unit, floor or building', 'Type your full name', 'Select',
        'Welcome, :name', 'Guest :position of :total', 'Agree to all', 'Untick all', ':ticked of :total confirmed', 'Your stay', 'Personal', 'Contact', 'Identity', 'Preferences', ':count to confirm', 'Signature needed', 'Full screen', 'Thank you.',
        'Next: :name (:position of :total)', 'Continue', 'All done. Please return the device to the front desk.', 'Staff: back to desk',
    ];

    /**
     * @return array<string, string> English text to the text in the language
     */
    public function for(Locale $locale): array
    {
        $keys = [
            ...array_map(fn (RegistrationCardField $field): string => $field->label(), RegistrationCardField::cases()),
            ...array_map(fn (Gender $gender): string => $gender->label(), Gender::cases()),
            ...array_map(fn (IdentityDocumentType $type): string => $type->label(), IdentityDocumentType::cases()),
            ...self::INTERFACE_WORDS,
        ];

        return array_combine($keys, array_map(fn (string $key): string => __($key, [], $locale->value), $keys));
    }
}
