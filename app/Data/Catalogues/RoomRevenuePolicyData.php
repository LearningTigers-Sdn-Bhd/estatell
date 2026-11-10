<?php

namespace App\Data\Catalogues;

use App\Enums\PolicyChargeMode;
use App\Enums\PolicyRateBasis;
use App\Enums\PolicyScope;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomRevenuePolicyStep;
use Illuminate\Support\Collection;
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
        /** @var list<RoomRevenuePolicyStepData> The charge steps of a paid cancellation, most days first. Empty for other policies. */
        public array $steps,
        public bool $ownTaxes,
        /** @var list<int> The taxes of the policy: its own when `ownTaxes`, otherwise the Taxes tab selection. */
        public array $taxIds,
        public string $textEn,
        public string $textMs,
    ) {}

    /**
     * @param  list<int>  $taxIds
     * @param  Collection<int, RoomRevenuePolicyStep>  $steps
     */
    public static function fromModel(RoomRevenuePolicy $policy, array $taxIds, Collection $steps): self
    {
        return new self(
            chargeMode: $policy->charge_mode,
            amount: $policy->amount,
            percent: $policy->percent,
            percentBasis: $policy->percent_basis,
            appliesTo: $policy->applies_to,
            steps: array_values($steps->map(fn (RoomRevenuePolicyStep $step): RoomRevenuePolicyStepData => RoomRevenuePolicyStepData::fromModel($step))->all()),
            ownTaxes: $policy->own_taxes,
            taxIds: $taxIds,
            textEn: $policy->text_en,
            textMs: $policy->text_ms,
        );
    }
}
