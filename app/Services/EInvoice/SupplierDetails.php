<?php

namespace App\Services\EInvoice;

use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use App\Models\Tax;

/**
 * The hotel as LHDN sees it on every e-invoice (the supplier): each detail from E-invoice settings, else from the
 * property. `missing()` lists what is empty or in the wrong form, so e-invoicing cannot be switched on and nothing is
 * sent until the hotel completes it; no detail is ever made up. A hotel that charges SST or tourism tax must give its
 * registration number for it. See docs/31-einvoice/01-lhdn-myinvois.md#hotel-details.
 */
class SupplierDetails
{
    /**
     * @return array{name: string, tin: string, brn: string, sst: string, ttx: string, msic: string, description: string, email: string, phone: string, address_line1: string, address_line2: string, city: string, postal_code: string, state_code: string}
     */
    public function for(Property $property, ?PropertyEInvoiceSetting $setting = null): array
    {
        $setting ??= $property->eInvoiceSetting;
        $pick = fn (?string $own, ?string $fallback = null): string => trim((string) ($own ?: $fallback));

        return [
            'name' => $pick($setting?->supplier_name, $property->legal_name ?: $property->name),
            'tin' => strtoupper((string) preg_replace('/\s+/', '', $pick($setting?->supplier_tin, $property->tin))),
            'brn' => $this->registrationNumber($pick($setting?->supplier_brn, $property->registration_number)),
            'sst' => $pick($setting?->supplier_sst_registration),
            'ttx' => $pick($setting?->supplier_ttx_registration),
            'msic' => $pick($setting?->supplier_msic_code, $property->msic_code),
            'description' => $pick($setting?->supplier_business_description, $property->business_description) ?: 'Hotel and accommodation services',
            'email' => $pick($setting?->supplier_email, $property->contact_email),
            'phone' => $pick($setting?->supplier_phone, $property->contact_phone),
            'address_line1' => $pick($setting?->supplier_address_line1, $property->address_line1),
            'address_line2' => $pick($setting?->supplier_address_line2, $property->address_line2),
            'city' => $pick($setting?->supplier_city, $property->city),
            'postal_code' => $pick($setting?->supplier_postal_code, $property->postal_code),
            'state_code' => MalaysiaStates::codeFor($setting?->supplier_state_code) ?? MalaysiaStates::codeFor($property->state_code) ?? '',
        ];
    }

    /**
     * What is missing or wrong, keyed by the E-invoice settings field, in words a hotel manager can act on.
     *
     * @return array<string, string>
     */
    public function missing(Property $property, ?PropertyEInvoiceSetting $setting = null): array
    {
        $details = $this->for($property, $setting);
        $taxCodes = Tax::query()->where('property_id', $property->id)->where('enabled', true)->pluck('code')->map(fn (string $code): string => strtoupper($code));

        $problems = [
            'supplier_name' => $details['name'] === '' ? __('Enter the registered business name.') : null,
            'supplier_tin' => match (true) {
                $details['tin'] === '' => __("Enter the hotel's tax number (TIN)."),
                preg_match('/^[A-Z]{1,2}\d{8,12}$/', $details['tin']) !== 1 => __('Enter the tax number as letters then digits, such as C12345678900.'),
                default => null,
            },
            'supplier_brn' => match (true) {
                $details['brn'] === '' => __('Enter the company registration number (SSM).'),
                preg_match('/^\d{12}$/', $details['brn']) !== 1 && preg_match('/^[A-Z0-9]{5,12}-?[A-Z]$/', $details['brn']) !== 1 => __('Enter the 12-digit company registration number, such as 202001012345.'),
                default => null,
            },
            'supplier_sst_registration' => $taxCodes->contains('SST') && $details['sst'] === '' ? __('The hotel charges SST, so enter its SST registration number.') : null,
            'supplier_ttx_registration' => $taxCodes->contains('TTX') && $details['ttx'] === '' ? __('The hotel charges tourism tax, so enter its tourism tax registration number.') : null,
            'supplier_msic_code' => preg_match('/^\d{5}$/', $details['msic']) !== 1 ? __('Enter the 5-digit MSIC code, such as 55101 for hotels.') : null,
            'supplier_email' => filter_var($details['email'], FILTER_VALIDATE_EMAIL) === false ? __('Enter an email for e-invoice matters.') : null,
            'supplier_phone' => preg_match('/\d{7,}/', (string) preg_replace('/\D/', '', $details['phone'])) !== 1 ? __("Enter the hotel's phone number.") : null,
            'supplier_address_line1' => $details['address_line1'] === '' ? __("Enter the hotel's street address.") : null,
            'supplier_city' => $details['city'] === '' ? __("Enter the hotel's city.") : null,
            'supplier_postal_code' => preg_match('/^\d{5}$/', $details['postal_code']) !== 1 ? __('Enter the 5-digit postcode.') : null,
            'supplier_state_code' => $details['state_code'] === '' ? __("Choose the hotel's state.") : null,
        ];

        return array_filter($problems);
    }

    /**
     * The supplier party of a UBL document. Call `missing()` first: this builds from the details as they are.
     *
     * @return array<string, mixed>
     */
    public function party(Property $property): array
    {
        $details = $this->for($property);

        $identifications = [
            ['ID' => [['_' => $details['tin'], 'schemeID' => 'TIN']]],
            ['ID' => [['_' => $details['brn'], 'schemeID' => 'BRN']]],
        ];
        if ($details['sst'] !== '') {
            $identifications[] = ['ID' => [['_' => $details['sst'], 'schemeID' => 'SST']]];
        }
        if ($details['ttx'] !== '') {
            $identifications[] = ['ID' => [['_' => $details['ttx'], 'schemeID' => 'TTX']]];
        }

        return [
            'IndustryClassificationCode' => [['_' => $details['msic'], 'name' => $details['description']]],
            'PartyIdentification' => $identifications,
            'PostalAddress' => [
                [
                    'CityName' => [['_' => $details['city']]],
                    'PostalZone' => [['_' => $details['postal_code']]],
                    'CountrySubentityCode' => [['_' => $details['state_code']]],
                    'AddressLine' => [
                        ['Line' => [['_' => $details['address_line1']]]],
                        ['Line' => [['_' => $details['address_line2']]]],
                        ['Line' => [['_' => '']]],
                    ],
                    'Country' => [['IdentificationCode' => [['_' => 'MYS']]]],
                ],
            ],
            'PartyLegalEntity' => [['RegistrationName' => [['_' => $details['name']]]]],
            'Contact' => [
                [
                    'Telephone' => [['_' => PhoneFormatter::format($details['phone'])]],
                    'ElectronicMail' => [['_' => $details['email']]],
                ],
            ],
        ];
    }

    /**
     * The new 12-digit SSM number out of forms such as "201901012345 (123456-A)"; an old-style number is kept as typed.
     */
    private function registrationNumber(string $value): string
    {
        if (preg_match('/\d{12}/', $value, $match) === 1) {
            return $match[0];
        }

        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }
}
