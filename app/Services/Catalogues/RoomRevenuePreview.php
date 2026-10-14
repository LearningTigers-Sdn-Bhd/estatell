<?php

namespace App\Services\Catalogues;

use App\Models\Property;
use Brick\Money\Money;

class RoomRevenuePreview
{
    /** The room-night price the preview uses. */
    public const SAMPLE_AMOUNT = '100.00';

    /** A nationality for the foreign guest sample. It is never the property's country. */
    private const FOREIGN_NATIONALITY = 'SG';

    private const FOREIGN_NATIONALITY_FOR_SINGAPORE = 'MY';

    public function __construct(private TaxCalculator $taxCalculator) {}

    /**
     * Calculate the chosen taxes on one sample room night, for a local and a foreign guest.
     * The taxes do not need to be saved yet.
     *
     * @param  list<int>  $taxIds
     * @return array{sampleAmount: Money, localGuest: TaxCalculationResult, foreignGuest: TaxCalculationResult}
     */
    public function calculate(Property $property, array $taxIds): array
    {
        $sampleAmount = Money::of(self::SAMPLE_AMOUNT, $property->settings->currency->getCurrencyCode());
        $taxes = $property->taxes()->whereKey($taxIds)->get();

        return [
            'sampleAmount' => $sampleAmount,
            'localGuest' => $this->taxCalculator->calculateWithTaxes($property, $taxes, $sampleAmount, '1', $property->country_code),
            'foreignGuest' => $this->taxCalculator->calculateWithTaxes($property, $taxes, $sampleAmount, '1', $this->foreignNationality($property)),
        ];
    }

    private function foreignNationality(Property $property): string
    {
        return mb_strtoupper((string) $property->country_code) === self::FOREIGN_NATIONALITY
            ? self::FOREIGN_NATIONALITY_FOR_SINGAPORE
            : self::FOREIGN_NATIONALITY;
    }
}
