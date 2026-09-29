<?php

namespace App\Data\Catalogues;

use App\Enums\PaymentMethodType;
use App\Enums\SurchargeType;
use App\Models\PaymentMethod;
use Spatie\LaravelData\Data;

/** One row of the payment-method list and the values of its edit form. */
class PaymentMethodData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public PaymentMethodType $type,
        public string $typeLabel,
        public string $transactionCode,
        public string $code,
        public bool $isSystem,
        public SurchargeType $surchargeType,
        public ?string $surchargeValue,
        public ?int $surchargeChargeItemId,
        public ?string $surchargeChargeItem,
        public bool $requiresReference,
        public bool $active,
    ) {}

    public static function fromModel(PaymentMethod $paymentMethod): self
    {
        return new self(
            id: $paymentMethod->id,
            name: $paymentMethod->name,
            type: $paymentMethod->type,
            typeLabel: $paymentMethod->type->label(),
            transactionCode: __(':code — :name', [
                'code' => $paymentMethod->transactionCode->code,
                'name' => $paymentMethod->transactionCode->name,
            ]),
            code: $paymentMethod->transactionCode->code,
            isSystem: $paymentMethod->transactionCode->system_key !== null,
            surchargeType: $paymentMethod->surcharge_type,
            surchargeValue: $paymentMethod->surcharge_value,
            surchargeChargeItemId: $paymentMethod->surcharge_charge_item_id,
            surchargeChargeItem: $paymentMethod->surchargeChargeItem?->name,
            requiresReference: $paymentMethod->requires_reference,
            active: $paymentMethod->active,
        );
    }
}
