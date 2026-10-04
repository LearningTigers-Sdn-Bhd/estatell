<?php

namespace App\Services\Folio;

use App\Models\Property;
use App\Services\Guests\CountryOptions;

/**
 * The property block at the top of a printed document: name, address, contact and registration lines. Shared by the
 * receipt and the voucher. It reads and writes nothing else.
 */
class Letterhead
{
    public function __construct(private CountryOptions $countryOptions) {}

    /**
     * @return array{name: string, addressLines: list<string>, contactLines: list<string>, registrationLines: list<string>}
     */
    public function for(Property $property): array
    {
        return [
            'name' => $property->name,
            'addressLines' => array_values(array_filter([
                $property->address_line1,
                $property->address_line2,
                trim(($property->postal_code ?? '').' '.($property->city ?? '')),
                $this->countryOptions->name($property->country_code),
            ], filled(...))),
            'contactLines' => array_values(array_filter([$property->contact_phone, $property->contact_email], filled(...))),
            'registrationLines' => $this->registrationLines($property),
        ];
    }

    /**
     * The registration number and the TIN, each only when set.
     *
     * @return list<string>
     */
    private function registrationLines(Property $property): array
    {
        $lines = [];

        if (filled($property->registration_number)) {
            $lines[] = __('Registration no. :number', ['number' => $property->registration_number]);
        }

        if (filled($property->tin)) {
            $lines[] = __('TIN :number', ['number' => $property->tin]);
        }

        return $lines;
    }
}
