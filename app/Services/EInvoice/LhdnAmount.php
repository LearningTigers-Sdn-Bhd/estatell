<?php

namespace App\Services\EInvoice;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * An amount as an LHDN document carries it: a JSON number. Every sum is made with `BigDecimal`; only the finished
 * amount becomes a number here, rounded half up to the sen (or to 4 places for a unit price).
 */
final class LhdnAmount
{
    /**
     * @param  int<0, max>  $scale
     */
    public static function of(BigNumber|string|int $amount, int $scale = 2): float
    {
        return BigDecimal::of($amount)->toScale($scale, RoundingMode::HalfUp)->toFloat();
    }
}
