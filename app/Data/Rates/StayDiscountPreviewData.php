<?php

namespace App\Data\Rates;

use App\Models\RoomType;
use App\Services\Rates\RateQuote;
use Brick\Money\Money;
use Spatie\LaravelData\Data;

/** The price preview of the Discounts tab: each room type by stay length, and one sample stay night by night. */
class StayDiscountPreviewData extends Data
{
    /**
     * @param  list<int>  $stayLengths
     * @param  list<StayDiscountPreviewRowData>  $rows
     */
    public function __construct(
        public string $arrival,
        public int $nights,
        public array $stayLengths,
        public int $ignoredRules,
        public array $rows,
    ) {}

    /**
     * @param  list<int>  $stayLengths
     * @param  list<array{roomType: RoomType, adults: int, children: int, unit: string, refusal: ?RateQuote, stays: array<int, RateQuote>, nightAmounts: array<int, list<Money>>, byNight: ?array{quote: RateQuote, unitAmounts: list<Money>}}>  $rows  From `StayDiscountPreview::preview()`
     */
    public static function fromPreview(string $arrival, int $nights, array $stayLengths, int $ignoredRules, array $rows): self
    {
        return new self(
            arrival: $arrival,
            nights: $nights,
            stayLengths: $stayLengths,
            ignoredRules: $ignoredRules,
            rows: array_map(StayDiscountPreviewRowData::fromRow(...), $rows),
        );
    }
}
