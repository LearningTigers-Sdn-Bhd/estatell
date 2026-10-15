<?php

namespace App\Services\Catalogues;

use App\Models\Property;
use App\Models\Tax;
use Brick\Money\Money;
use Illuminate\Support\Collection;

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
        return $this->calculateTaxes($property, $property->taxes()->whereKey($taxIds)->get());
    }

    /**
     * The same sample night for taxes that are already loaded, or built in memory and never saved.
     *
     * @param  Collection<int, Tax>  $taxes
     * @return array{sampleAmount: Money, localGuest: TaxCalculationResult, foreignGuest: TaxCalculationResult}
     */
    public function calculateTaxes(Property $property, Collection $taxes): array
    {
        $sampleAmount = Money::of(self::SAMPLE_AMOUNT, $property->settings->currency->getCurrencyCode());

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
