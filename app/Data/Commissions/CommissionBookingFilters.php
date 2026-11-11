<?php

namespace App\Data\Commissions;

use App\Enums\CommissionPeriod;
use App\Enums\CommissionService;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * The filters of the admin Commissions Bookings tab, read from the URL. The period runs from `from` to `to`, both included.
 */
class CommissionBookingFilters extends Data
{
    public function __construct(
        public CommissionPeriod $period,
        public string $date,
        public string $from,
        public string $to,
        public ?int $propertyId,
        public ?CommissionService $service,
    ) {}

    public static function make(CommissionPeriod $period, CarbonImmutable $date, ?int $propertyId, ?CommissionService $service): self
    {
        [$from, $to] = $period->range($date);

        return new self($period, $date->toDateString(), $from->toDateString(), $to->toDateString(), $propertyId, $service);
    }
}
