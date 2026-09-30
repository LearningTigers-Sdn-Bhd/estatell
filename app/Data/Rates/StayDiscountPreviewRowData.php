<?php

namespace App\Data\Rates;

use App\Models\RoomType;
use App\Services\Rates\RateQuote;
use App\Services\Rates\StayDiscountPreview;
use Brick\Money\Money;
use Spatie\LaravelData\Data;

/** One room type of the discount preview, or the reason it cannot be priced. `unit` is what a night amount is for. */
class StayDiscountPreviewRowData extends Data
{
    /**
     * @param  list<StayDiscountPreviewStayData>  $stays
     */
    public function __construct(
        public int $roomTypeId,
        public string $name,
        public int $adults,
        public int $children,
        public string $unit,
        public ?string $refusal,
        public array $stays,
        public ?StayDiscountPreviewByNightData $byNight,
    ) {}

    /**
     * @param  array{roomType: RoomType, adults: int, children: int, unit: string, refusal: ?RateQuote, stays: array<int, RateQuote>, nightAmounts: array<int, list<Money>>, byNight: ?array{quote: RateQuote, unitAmounts: list<Money>}}  $row
     */
    public static function fromRow(array $row): self
    {
        $stays = [];

        foreach ($row['stays'] as $length => $quote) {
            $stays[] = new StayDiscountPreviewStayData(
                nights: $length,
                total: (string) $quote->total->getAmount(),
                discount: (string) $quote->discountAmount->getAmount(),
                nightAmounts: array_map(fn (Money $amount): string => (string) $amount->getAmount(), $row['nightAmounts'][$length]),
            );
        }

        return new self(
            roomTypeId: $row['roomType']->id,
            name: $row['roomType']->name,
            adults: $row['adults'],
            children: $row['children'],
            unit: $row['unit'],
            refusal: $row['refusal'] === null ? null : self::refusalMessage($row['refusal']),
            stays: $stays,
            byNight: $row['byNight'] === null ? null : StayDiscountPreviewByNightData::fromQuote($row['byNight']['quote'], $row['byNight']['unitAmounts']),
        );
    }

    private static function refusalMessage(RateQuote $quote): string
    {
        return match ($quote->refusal) {
            RateQuote::REFUSAL_RATE_MISSING => __('No rate on :date.', ['date' => $quote->refusalDate?->translatedFormat('j M Y')]),
            RateQuote::REFUSAL_CHILD_PRICE_MISSING => __('Set child prices on the Children tab first.'),
            StayDiscountPreview::REFUSAL_TOO_MANY_GUESTS => __('Too many guests for this room type.'),
            default => __('This room type cannot be priced.'),
        };
    }
}
