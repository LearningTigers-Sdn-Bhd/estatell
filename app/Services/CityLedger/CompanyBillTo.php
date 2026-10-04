<?php

namespace App\Services\CityLedger;

use App\Models\Company;
use App\Services\Guests\CountryOptions;

/**
 * The "bill to" lines of a company on a printed document: billing address, then the tax numbers it has. It reads
 * nothing else and writes nothing.
 */
class CompanyBillTo
{
    public function __construct(private CountryOptions $countryOptions) {}

    /**
     * @return list<string>
     */
    public function lines(Company $company): array
    {
        return array_values(array_filter([
            $company->billing_address_line1,
            $company->billing_address_line2,
            trim(($company->billing_postal_code ?? '').' '.($company->billing_city ?? '').' '.($company->billing_state_code ?? '')),
            $this->countryOptions->name($company->billing_country_code),
            filled($company->brn) ? __('Registration no. :number', ['number' => $company->brn]) : null,
            filled($company->tin) ? __('TIN :number', ['number' => $company->tin]) : null,
            filled($company->sst_registration_number) ? __('SST no. :number', ['number' => $company->sst_registration_number]) : null,
        ], filled(...)));
    }
}
