<?php

namespace App\Data\Onboarding;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Models\Discount;
use Spatie\LaravelData\Data;

/**
 * One saved discount as a row of the onboarding Discounts table. A starter discount (it owns a system posting code)
 * shows its code as fixed text. `roomCharges` is true when the Selected scope holds every room revenue code, and
 * `transactionCodeIds` holds the other chosen charge codes.
 */
class OnboardingDiscountData extends Data
{
    /** @param list<int> $transactionCodeIds */
    public function __construct(
        public int $id,
        public string $name,
        public string $code,
        public bool $isStarter,
        public DiscountType $type,
        public ?string $value,
        public bool $allowOverride,
        public DiscountScope $scope,
        public bool $active,
        public bool $roomCharges,
        public array $transactionCodeIds,
    ) {}

    /**
     * @param  list<int>  $roomCodeIds
     */
    public static function fromModel(Discount $discount, array $roomCodeIds): self
    {
        $selected = $discount->selectedTransactionCodes
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $roomCharges = $roomCodeIds !== [] && array_diff($roomCodeIds, $selected) === [];

        return new self(
            id: $discount->id,
            name: $discount->name,
            code: $discount->transactionCode->code,
            isStarter: $discount->transactionCode->system_key !== null,
            type: $discount->type,
            value: $discount->value,
            allowOverride: $discount->allow_override,
            scope: $discount->scope,
            active: $discount->active,
            roomCharges: $roomCharges,
            transactionCodeIds: array_values(array_diff($selected, $roomCharges ? $roomCodeIds : [])),
        );
    }
}
