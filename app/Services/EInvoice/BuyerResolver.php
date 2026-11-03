<?php

namespace App\Services\EInvoice;

use App\Enums\IdentityDocumentType;
use App\Models\Company;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;

class BuyerResolver
{
    public const string FOREIGN_BUYER_TIN = 'EI00000000020';

    public const string GENERAL_PUBLIC_TIN = 'EI00000000010';

    public const string DOMESTIC_BUYER_FALLBACK_TIN = 'EI00000000000';

    /**
     * @return array{
     *     name: string,
     *     tin: string,
     *     id_type: string,
     *     id_value: string,
     *     sst_registration: ?string,
     *     email: string,
     *     phone: string,
     *     address_line1: string,
     *     address_line2: string,
     *     city: string,
     *     postal_code: string,
     *     state_code: string,
     *     country_code: string
     * }
     */
    public static function forFolio(Folio $folio): array
    {
        if ($folio->payerCompany !== null) {
            return self::forCompany($folio->payerCompany, $folio->property);
        }

        return self::forGuest($folio->payerGuest, $folio->property);
    }

    /**
     * @return array{
     *     name: string,
     *     tin: string,
     *     id_type: string,
     *     id_value: string,
     *     sst_registration: ?string,
     *     email: string,
     *     phone: string,
     *     address_line1: string,
     *     address_line2: string,
     *     city: string,
     *     postal_code: string,
     *     state_code: string,
     *     country_code: string
     * }
     */
    public static function forCompany(Company $company, Property $property): array
    {
        $countryCode = strtoupper(trim($company->billing_country_code ?? 'MYS'));
        if (strlen($countryCode) === 2 && $countryCode === 'MY') {
            $countryCode = 'MYS';
        }

        $stateCode = MalaysiaStates::resolve(
            $company->billing_state_code,
            $company->billing_city,
            $countryCode
        );

        $tin = trim((string) ($company->tin ?: self::DOMESTIC_BUYER_FALLBACK_TIN));
        $brn = trim((string) ($company->brn ?: 'NA'));

        return [
            'name' => $company->name,
            'tin' => $tin,
            'id_type' => 'BRN',
            'id_value' => preg_replace('/[^A-Za-z0-9]/', '', $brn) ?: 'NA',
            'sst_registration' => $company->sst_registration_number,
            'email' => $company->contact_email ?: ($property->contact_email ?: 'guest@hotel.com'),
            'phone' => PhoneFormatter::format($company->contact_phone ?: $property->contact_phone),
            'address_line1' => $company->billing_address_line1 ?: ($property->address_line1 ?: 'NA'),
            'address_line2' => (string) ($company->billing_address_line2 ?: ''),
            'city' => $company->billing_city ?: ($property->city ?: 'Kota Kinabalu'),
            'postal_code' => $company->billing_postal_code ?: ($property->postal_code ?: '88000'),
            'state_code' => $stateCode,
            'country_code' => $countryCode ?: 'MYS',
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     tin: string,
     *     id_type: string,
     *     id_value: string,
     *     sst_registration: ?string,
     *     email: string,
     *     phone: string,
     *     address_line1: string,
     *     address_line2: string,
     *     city: string,
     *     postal_code: string,
     *     state_code: string,
     *     country_code: string
     * }
     */
    public static function forGuest(?Guest $guest, Property $property): array
    {
        $guestName = $guest?->full_name ?: 'Hotel Guest';
        $countryCode = strtoupper(trim($guest?->country_code ?? $guest?->nationality_code ?? 'MYS'));
        if (strlen($countryCode) === 2 && $countryCode === 'MY') {
            $countryCode = 'MYS';
        }

        $isForeign = ($countryCode !== 'MYS' && $countryCode !== 'MY');

        // Resolve Identity Document
        $idType = 'NRIC';
        $idValue = 'NA';

        if ($guest !== null) {
            /** @var GuestIdentityDocument|null $passportDoc */
            $passportDoc = $guest->documents()->where('type', IdentityDocumentType::Passport)->first();
            /** @var GuestIdentityDocument|null $nricDoc */
            $nricDoc = $guest->documents()->where('type', IdentityDocumentType::MyKad)->first();

            if ($isForeign) {
                $idType = 'PASSPORT';
                $idValue = $passportDoc?->number ?: 'NA';
            } elseif ($nricDoc !== null) {
                $idType = 'NRIC';
                $idValue = $nricDoc->number;
            } elseif ($passportDoc !== null) {
                $idType = 'PASSPORT';
                $idValue = $passportDoc->number;
            }
        }

        $tin = trim((string) ($guest?->tin ?? ''));
        if (empty($tin)) {
            $tin = $isForeign ? self::FOREIGN_BUYER_TIN : self::DOMESTIC_BUYER_FALLBACK_TIN;
        }

        $stateCode = MalaysiaStates::resolve(
            $guest?->state_code,
            $guest?->city,
            $countryCode
        );

        return [
            'name' => $guestName,
            'tin' => $tin,
            'id_type' => $idType,
            'id_value' => preg_replace('/[^A-Za-z0-9]/', '', $idValue) ?: 'NA',
            'sst_registration' => null,
            'email' => $guest?->email ?: ($property->contact_email ?: 'guest@hotel.com'),
            'phone' => PhoneFormatter::format($guest?->phone ?: $property->contact_phone),
            'address_line1' => $guest?->address_line1 ?: ($property->address_line1 ?: 'NA'),
            'address_line2' => (string) ($guest?->address_line2 ?: ''),
            'city' => $guest?->city ?: ($property->city ?: 'Kota Kinabalu'),
            'postal_code' => $guest?->postal_code ?: ($property->postal_code ?: '88000'),
            'state_code' => $stateCode,
            'country_code' => $countryCode ?: 'MYS',
        ];
    }
}
