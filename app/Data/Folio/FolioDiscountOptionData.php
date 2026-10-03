<?php

namespace App\Data\Folio;

use App\Enums\DiscountType;
use App\Models\Discount;
use Spatie\LaravelData\Data;

/**
 * An active discount to pick on a folio. `canEnterAmount` is true when this user may type the amount: an Open
 * discount, or a Fixed one with `allow_override`, and the user holds `folio.discount-open`. Otherwise the amount
 * comes from the discount.
 */
class FolioDiscountOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public string $typeLabel,
        public ?string $value,
        public string $scopeLabel,
        public bool $canEnterAmount,
    ) {}

    public static function fromModel(Discount $discount, bool $canEnterOpenAmounts): self
    {
        return new self(
            id: $discount->id,
            name: $discount->name,
            type: $discount->type->value,
            typeLabel: $discount->type->label(),
            value: $discount->value,
            scopeLabel: $discount->scope->label(),
            canEnterAmount: $canEnterOpenAmounts && ($discount->type === DiscountType::Open || ($discount->type === DiscountType::Fixed && $discount->allow_override)),
        );
    }
}
