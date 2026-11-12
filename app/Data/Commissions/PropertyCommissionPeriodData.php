<?php

namespace App\Data\Commissions;

use App\Enums\CommissionService;
use App\Models\PropertyCommissionPeriod;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One charging period of a property, for the admin property Commissions tab.
 */
class PropertyCommissionPeriodData extends Data
{
    public function __construct(
        public int $id,
        public CommissionService $service,
        public string $serviceLabel,
        public string $startsOn,
        public ?string $endsOn,
        public bool $hasStarted,
        public ?string $createdBy,
    ) {}

    public static function fromModel(PropertyCommissionPeriod $period, CarbonImmutable $today): self
    {
        return new self(
            id: $period->id,
            service: $period->service,
            serviceLabel: $period->service->label(),
            startsOn: $period->starts_on->toDateString(),
            endsOn: $period->ends_on?->toDateString(),
            hasStarted: $period->starts_on->toDateString() <= $today->toDateString(),
            createdBy: $period->creator?->name,
        );
    }
}
