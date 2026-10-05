<?php

namespace App\Data\Catalogues;

use App\Enums\PolicyChargeMode;
use App\Enums\PolicyRateBasis;
use App\Enums\PolicyScope;
use App\Models\RoomRevenuePolicy;
use Spatie\LaravelData\Data;

/** One room revenue policy as the form edits it. */
class RoomRevenuePolicyData extends Data
{
    public function __construct(
        public PolicyChargeMode $chargeMode,
        public ?string $amount,
        public ?string $percent,
        public ?PolicyRateBasis $percentBasis,
        public PolicyScope $appliesTo,
        public ?int $freeUntilDays,
        public string $textEn,
        public string $textMs,
    ) {}

    public static function fromModel(RoomRevenuePolicy $policy): self
    {
        return new self(
            chargeMode: $policy->charge_mode,
            amount: $policy->amount,
            percent: $policy->percent,
            percentBasis: $policy->percent_basis,
            appliesTo: $policy->applies_to,
            freeUntilDays: $policy->free_until_days,
            textEn: $policy->text_en,
            textMs: $policy->text_ms,
        );
    }
}
