<?php

namespace App\Data\Rates;

use App\Services\Rates\Pricing\NightPrice;
use App\Services\Rates\RateQuote;
use Brick\Money\Money;
use Spatie\LaravelData\Data;

/** The sample stay of the By night view: each night on its own, then the total. */
class StayDiscountPreviewByNightData extends Data
{
    /**
     * @param  list<StayDiscountPreviewNightData>  $nights
     */
    public function __construct(
        public array $nights,
        public string $total,
        public string $discount,
    ) {}

    /**
     * @param  list<Money>  $unitAmounts
     */
    public static function fromQuote(RateQuote $quote, array $unitAmounts): self
    {
        return new self(
            nights: array_map(
                fn (NightPrice $night, Money $unitAmount): StayDiscountPreviewNightData => new StayDiscountPreviewNightData(
                    amount: (string) $night->net()->getAmount(),
                    discount: (string) $night->discountAmount->getAmount(),
                    unitAmount: (string) $unitAmount->getAmount(),
                ),
                $quote->nights,
                $unitAmounts,
            ),
            total: (string) $quote->total->getAmount(),
            discount: (string) $quote->discountAmount->getAmount(),
        );
    }
}
