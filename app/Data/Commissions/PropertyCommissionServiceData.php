<?php

namespace App\Data\Commissions;

use App\Enums\CommissionService;
use Spatie\LaravelData\Data;

/**
 * One service on the admin property Commissions tab: today's platform rate and the period that is running or comes next.
 * With no such period, the property is not charged for the service.
 */
class PropertyCommissionServiceData extends Data
{
    public function __construct(
        public CommissionService $service,
        public string $label,
        public ?string $rate,
        public ?PropertyCommissionPeriodData $period,
    ) {}
}
