<?php

namespace App\Services\Reports;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The rounding the room statistics reports share (History & forecast, Rooms on books): a percentage, an amount per
 * night and a money total, each a decimal string with two decimals. A zero or negative divisor gives `0.00`.
 */
final class ReportFigures
{
    public static function percent(int $part, int $whole): string
    {
        return $whole === 0 ? '0.00' : (string) BigDecimal::of($part)->multipliedBy(100)->dividedBy($whole, 2, RoundingMode::HalfUp);
    }

    public static function perUnit(BigDecimal $amount, int $units): string
    {
        return $units <= 0 ? '0.00' : (string) $amount->dividedBy($units, 2, RoundingMode::HalfUp);
    }

    public static function money(BigDecimal $amount): string
    {
        return (string) $amount->toScale(2, RoundingMode::HalfUp);
    }
}
