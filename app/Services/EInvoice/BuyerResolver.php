<?php

namespace App\Services\EInvoice;

use App\Enums\IdentityDocumentType;
use App\Models\Company;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use App\Services\Guests\CountryOptions;

class BuyerResolver
{
    public const string FOREIGN_BUYER_TIN = 'EI00000000020';

    public const string GENERAL_PUBLIC_TIN = 'EI00000000010';

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
    public static function forFolio(Folio $folio, ?Property $property = null): array
    {
        $property ??= $folio->relationLoaded('property') ? $folio->property : $folio->property;

        if ($folio->payerCompany !== null) {
            return self::forCompany($folio->payerCompany, $property);
        }

        return self::forGuest($folio->payerGuest, $property);
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
        $countryCode = self::lhdnCountryCode($company->billing_country_code);

        $stateCode = MalaysiaStates::resolve(
            $company->billing_state_code,
            $company->billing_city,
            $countryCode
        );

        $tin = trim((string) ($company->tin ?: ''));
        $brn = trim((string) ($company->brn ?: 'NA'));

        return [
            'name' => $company->name,
            'tin' => $tin,
            'id_type' => 'BRN',
            'id_value' => preg_replace('/[^A-Za-z0-9]/', '', $brn) ?: 'NA',
            'sst_registration' => $company->sst_registration_number,
            'email' => (string) ($company->contact_email ?: ''),
            'phone' => PhoneFormatter::format($company->contact_phone ?: $property->contact_phone),
            'address_line1' => (string) ($company->billing_address_line1 ?: 'NA'),
            'address_line2' => (string) ($company->billing_address_line2 ?: ''),
            'city' => (string) ($company->billing_city ?: ''),
            'postal_code' => (string) ($company->billing_postal_code ?: ''),
            'state_code' => $stateCode,
            'country_code' => $countryCode,
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
        $countryCode = self::lhdnCountryCode($guest->country_code ?? $guest?->nationality_code);

        $isForeign = $countryCode !== 'MYS';

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

        $tin = trim((string) ($guest->tin ?? ''));
        if (empty($tin)) {
            // Foreign buyers without a TIN use LHDN general foreign TIN; domestic buyers must supply their own.
            $tin = $isForeign ? self::FOREIGN_BUYER_TIN : '';
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
            'email' => (string) ($guest?->email ?: ''),
            'phone' => PhoneFormatter::format($guest?->phone ?: $property->contact_phone),
            'address_line1' => (string) ($guest?->address_line1 ?: 'NA'),
            'address_line2' => (string) ($guest?->address_line2 ?: ''),
            'city' => (string) ($guest?->city ?: ''),
            'postal_code' => (string) ($guest?->postal_code ?: ''),
            'state_code' => $stateCode,
            'country_code' => $countryCode,
        ];
    }

    /**
     * LHDN needs ISO 3166-1 alpha-3 codes; guests and companies store alpha-2.
     * An empty code stays Malaysia. An unknown code is sent as it is, so LHDN
     * rejects it rather than the buyer being filed as Malaysian.
     */
    public static function lhdnCountryCode(?string $code): string
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return 'MYS';
        }

        return app(CountryOptions::class)->alpha3($code) ?? $code;
    }
}
