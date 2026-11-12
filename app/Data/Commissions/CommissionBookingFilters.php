<?php

namespace App\Data\Commissions;

use App\Enums\CommissionPeriod;
use App\Enums\CommissionService;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * The filters of the admin Commissions and the property Payouts Bookings tabs, read from the URL. The period runs from `from` to `to`, both included.
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
        /** Part of the booking reference or the booker's name. */
        public ?string $search = null,
    ) {}

    public static function make(CommissionPeriod $period, CarbonImmutable $date, ?int $propertyId, ?CommissionService $service, ?string $search = null): self
    {
        [$from, $to] = $period->range($date);
        $search = trim((string) $search);

        return new self($period, $date->toDateString(), $from->toDateString(), $to->toDateString(), $propertyId, $service, $search === '' ? null : $search);
    }
}
