<?php

namespace App\Services\Pos;

use App\Enums\PosRoundingMode;
use App\Enums\PosRoundingPolicy;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Bank Negara Malaysia cash rounding. Cash has no 1-sen coin, so a cash amount is rounded to the nearest 5 sen: sen ending
 * 1 or 2 go down, 3 or 4 go up, 6 or 7 go down, 8 or 9 go up, and 0 or 5 stay. Ten-sen rounding goes to the nearest 10
 * sen, with 5 going up (to verify with the property's accountant). Only cash is rounded: card and e-wallet pay the exact bill.
 * The signed adjustment is kept apart from the bill and booked to the outlet's rounding transaction code.
 */
class MalaysiaCashRounding
{
    /**
     * The adjustment to add to the cash due, negative when rounding down. With `CashLegOnly` the cash share of the bill is
     * rounded. With `WholeBillWhenCashPresent` the whole bill is rounded as soon as any cash is paid.
     */
    public function adjustment(PosRoundingMode $mode, PosRoundingPolicy $policy, string $grandTotal, string $cashAmount): string
    {
        $cash = BigDecimal::of($cashAmount);

        if ($mode === PosRoundingMode::None || ! $cash->isPositive()) {
            return '0.00';
        }

        $base = BigDecimal::of($policy === PosRoundingPolicy::WholeBillWhenCashPresent ? $grandTotal : $cashAmount);

        return $this->rounded($base, $mode)->minus($base)->toScale(2, RoundingMode::HalfUp)->__toString();
    }

    /**
     * The amount rounded for cash under the mode.
     */
    public function round(string $amount, PosRoundingMode $mode): string
    {
        return $this->rounded(BigDecimal::of($amount), $mode)->toScale(2, RoundingMode::HalfUp)->__toString();
    }

    private function rounded(BigDecimal $amount, PosRoundingMode $mode): BigDecimal
    {
        $sen = $amount->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();

        $step = match ($mode) {
            PosRoundingMode::None => 1,
            PosRoundingMode::FiveSen => 5,
            PosRoundingMode::TenSen => 10,
        };

        $remainder = $sen % $step;
        $roundUp = match ($mode) {
            PosRoundingMode::FiveSen => $remainder >= 3,
            PosRoundingMode::TenSen => $remainder >= 5,
            PosRoundingMode::None => false,
        };
        $roundedSen = $roundUp ? $sen + $step - $remainder : $sen - $remainder;

        return BigDecimal::of($roundedSen)->dividedBy(100, 2, RoundingMode::HalfUp);
    }
}
