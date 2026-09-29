<?php

namespace App\Data\Catalogues;

use App\Services\Catalogues\CalculatedTax;
use App\Services\Catalogues\TaxCalculationResult;
use Brick\Money\Money;
use Spatie\LaravelData\Data;

/** A sample room night with the saved room revenue taxes, for a local and a foreign guest. */
class RoomRevenuePreviewData extends Data
{
    /** @param list<RoomRevenuePreviewLineData> $lines */
    public function __construct(
        public string $sampleAmount,
        public ?string $refusal,
        public ?string $localNet,
        public ?string $foreignNet,
        public array $lines,
        public ?string $localTotal,
        public ?string $foreignTotal,
    ) {}

    /**
     * @param  array{sampleAmount: Money, localGuest: TaxCalculationResult, foreignGuest: TaxCalculationResult}  $calculation
     */
    public static function fromCalculation(array $calculation): self
    {
        $local = $calculation['localGuest'];
        $foreign = $calculation['foreignGuest'];

        if (! $local->isCalculated() || ! $foreign->isCalculated()) {
            return new self(
                sampleAmount: (string) $calculation['sampleAmount']->getAmount(),
                refusal: __('The taxes included in the price are more than the sample room price.'),
                localNet: null,
                foreignNet: null,
                lines: [],
                localTotal: null,
                foreignTotal: null,
            );
        }

        $localTaxes = collect($local->taxes)->keyBy('taxId');
        $foreignTaxes = collect($foreign->taxes)->keyBy('taxId');
        $lines = array_values($foreignTaxes->union($localTaxes)
            ->sortKeys()
            ->map(fn (CalculatedTax $tax): RoomRevenuePreviewLineData => new RoomRevenuePreviewLineData(
                taxId: $tax->taxId,
                name: $tax->name,
                inclusive: $tax->inclusive,
                localAmount: self::amount($localTaxes->get($tax->taxId)?->amount),
                foreignAmount: self::amount($foreignTaxes->get($tax->taxId)?->amount),
            ))
            ->all());

        return new self(
            sampleAmount: (string) $calculation['sampleAmount']->getAmount(),
            refusal: null,
            localNet: self::amount($local->netAmount),
            foreignNet: self::amount($foreign->netAmount),
            lines: $lines,
            localTotal: self::amount($local->grossAmount),
            foreignTotal: self::amount($foreign->grossAmount),
        );
    }

    private static function amount(?Money $money): ?string
    {
        return $money === null ? null : (string) $money->getAmount();
    }
}
