<?php

namespace App\Enums;

/**
 * Where a History & forecast row comes from: a closed date's audit and ledger (Actual), the open business date
 * (Today: the books and what is posted so far), or a later date (Forecast: the books only).
 * See docs/22-reports/04-history-and-forecast.md.
 */
enum ForecastBasis: string
{
    case Actual = 'actual';
    case Today = 'today';
    case Forecast = 'forecast';

    public function label(): string
    {
        return match ($this) {
            self::Actual => __('Actual'),
            self::Today => __('Today'),
            self::Forecast => __('Forecast'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Actual => Tone::Neutral,
            self::Today => Tone::Info,
            self::Forecast => Tone::Warning,
        };
    }
}
