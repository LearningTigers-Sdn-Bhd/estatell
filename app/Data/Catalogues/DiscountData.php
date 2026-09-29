<?php

namespace App\Data\Catalogues;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Models\Discount;
use Spatie\LaravelData\Data;

/**
 * One row of the discount list, and the values of its edit form.
 */
class DiscountData extends Data
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
        public array $transactionCodeIds,
    ) {}

    public static function fromModel(Discount $discount): self
    {
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
            transactionCodeIds: array_values($discount->selectedTransactionCodes
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->all()),
        );
    }
}
