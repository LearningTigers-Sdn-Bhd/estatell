<?php

namespace App\Services\Rates;

use App\Services\Rates\Pricing\NightPrice;
use Brick\Money\Money;
use Carbon\CarbonInterface;

/**
 * The priced nights of a stay and their total after discount, or the reason the stay cannot be priced.
 */
class RateQuote
{
    public const REFUSAL_INVALID_STAY = 'invalid_stay';

    public const REFUSAL_ADULTS_REQUIRED = 'adults_required';

    public const REFUSAL_BEDS_REQUIRED = 'beds_required';

    public const REFUSAL_PLAN_ARCHIVED = 'plan_archived';

    public const REFUSAL_ROOM_TYPE_NOT_SOLD = 'room_type_not_sold';

    public const REFUSAL_PACKAGE_PRICE_MISSING = 'package_price_missing';

    public const REFUSAL_STAY_LENGTH_MISMATCH = 'stay_length_mismatch';

    public const REFUSAL_PACKAGE_BELOW_INCLUSIONS = 'package_below_inclusions';

    public const REFUSAL_RATE_BELOW_INCLUSIONS = 'rate_below_inclusions';

    public const REFUSAL_RATE_MISSING = 'rate_missing';

    public const REFUSAL_CHILD_PRICE_MISSING = 'child_price_missing';

    /**
     * @param  list<NightPrice>  $nights
     */
    private function __construct(
        public readonly array $nights,
        public readonly ?Money $total,
        public readonly ?Money $discountAmount,
        public readonly ?string $refusal,
        public readonly ?CarbonInterface $refusalDate,
    ) {}

    /**
     * @param  non-empty-list<NightPrice>  $nights
     */
    public static function quoted(array $nights): self
    {
        $zero = Money::zero($nights[0]->base->getCurrency());
        $total = $zero;
        $discount = $zero;

        foreach ($nights as $night) {
            $total = $total->plus($night->net());
            $discount = $discount->plus($night->discountAmount);
        }

        return new self($nights, $total, $discount, null, null);
    }

    public static function refused(string $reason, ?CarbonInterface $date = null): self
    {
        return new self([], null, null, $reason, $date);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
