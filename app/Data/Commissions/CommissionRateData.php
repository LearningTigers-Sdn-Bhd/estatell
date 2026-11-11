<?php

namespace App\Data\Commissions;

use App\Data\Shared\BadgeData;
use App\Enums\CommissionRateStatus;
use App\Enums\CommissionService;
use App\Models\CommissionRate;
use Spatie\LaravelData\Data;

class CommissionRateData extends Data
{
    public function __construct(
        public int $id,
        public CommissionService $service,
        public string $serviceLabel,
        public string $rate,
        public string $effectiveFrom,
        public BadgeData $status,
    ) {}

    public static function fromModel(CommissionRate $rate, CommissionRateStatus $status): self
    {
        return new self(
            id: $rate->id,
            service: $rate->service,
            serviceLabel: $rate->service->label(),
            rate: $rate->rate,
            effectiveFrom: $rate->effective_from->toDateString(),
            status: BadgeData::fromCommissionRateStatus($status),
        );
    }
}
