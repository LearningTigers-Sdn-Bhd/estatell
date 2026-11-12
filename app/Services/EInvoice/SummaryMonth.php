<?php

namespace App\Services\EInvoice;

use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * The month a monthly summary covers, in the property's own time zone: the month staff picked, or else the month
 * before the current one. It reads only.
 */
class SummaryMonth
{
    public function previous(Property $property): CarbonImmutable
    {
        return now($this->timeZone($property))->startOfMonth()->subMonth();
    }

    /**
     * @param  string  $month  `Y-m`, already validated.
     */
    public function parse(Property $property, string $month): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month, $this->timeZone($property));
    }

    private function timeZone(Property $property): string
    {
        return $property->settings->time_zone ?? config('app.timezone');
    }
}
