<?php

namespace App\Data\Property;

use App\Models\Property;
use App\Models\PropertyLicence;
use App\Services\Property\CountryProfiles;
use Spatie\LaravelData\Data;

/**
 * The legal details on the Business licence tab: registered name, numbers and licences. The labels come from the
 * country profile.
 */
class PropertyBusinessData extends Data
{
    /**
     * @param  list<PropertyLicenceData>  $licences
     */
    public function __construct(
        public ?string $legalName,
        public ?string $registrationNumber,
        public string $registrationNumberLabel,
        public ?string $tin,
        public string $tinLabel,
        public array $licences,
    ) {}

    public static function fromModel(Property $property): self
    {
        $profile = CountryProfiles::for((string) $property->country_code);

        return new self(
            legalName: $property->legal_name,
            registrationNumber: $property->registration_number,
            registrationNumberLabel: $profile->registrationNumberLabel(),
            tin: $property->tin,
            tinLabel: $profile->tinLabel(),
            licences: array_values($property->licences()->ordered()->get()
                ->map(fn (PropertyLicence $licence): PropertyLicenceData => PropertyLicenceData::fromModel($licence))
                ->all()),
        );
    }
}
