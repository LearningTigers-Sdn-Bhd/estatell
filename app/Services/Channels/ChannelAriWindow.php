<?php

namespace App\Services\Channels;

use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * The dates channels see: today in the property's time zone and the 499 days after it (500 days). Channex refuses
 * past dates, and nothing further out is sold.
 */
class ChannelAriWindow
{
    public const DAYS = 500;

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function forProperty(Property $property): array
    {
        $property->loadMissing('settings');
        $first = CarbonImmutable::parse(now($property->settings->time_zone ?? config('app.timezone'))->toDateString())->startOfDay();

        return [$first, $first->addDays(self::DAYS - 1)];
    }

    /**
     * Cut a date range to the window. Returns null when nothing is left.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function clamp(Property $property, string $dateFrom, string $dateTo): ?array
    {
        [$first, $last] = $this->forProperty($property);
        $from = CarbonImmutable::parse($dateFrom)->startOfDay()->max($first);
        $to = CarbonImmutable::parse($dateTo)->startOfDay()->min($last);

        return $from->gt($to) ? null : [$from, $to];
    }
}
