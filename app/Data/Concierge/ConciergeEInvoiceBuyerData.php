<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * The buyer details the e-invoice form starts with. The ID number, tax number, email, phone and street address are blank
 * unless the guest typed them on an earlier request, so the page never shows them to whoever holds the stay link.
 */
class ConciergeEInvoiceBuyerData extends Data
{
    public function __construct(
        public string $name,
        public string $idType,
        public string $idValue,
        public string $tin,
        public string $email,
        public string $phone,
        public string $addressLine1,
        public string $addressLine2,
        public string $city,
        public string $postalCode,
        public string $stateCode,
        public string $countryCode,
    ) {}

    /**
     * @param  array<string, mixed>  $buyer  A buyer snapshot (`BuyerResolver::forFolio`) with an alpha-2 country.
     */
    public static function fromBuyer(array $buyer, bool $keepTyped): self
    {
        $text = fn (string $key): string => trim((string) ($buyer[$key] ?? ''));
        $blankNa = fn (string $key): string => strtoupper($text($key)) === 'NA' ? '' : $text($key);

        return new self(
            name: $text('name'),
            idType: in_array($text('id_type'), ['NRIC', 'PASSPORT'], true) ? $text('id_type') : 'NRIC',
            idValue: $keepTyped ? $blankNa('id_value') : '',
            tin: $keepTyped ? $text('tin') : '',
            email: $keepTyped ? $text('email') : '',
            phone: $keepTyped ? $text('phone') : '',
            addressLine1: $keepTyped ? $blankNa('address_line1') : '',
            addressLine2: $keepTyped ? $text('address_line2') : '',
            city: $text('city'),
            postalCode: $text('postal_code'),
            stateCode: $text('state_code'),
            countryCode: $text('country_code'),
        );
    }
}
