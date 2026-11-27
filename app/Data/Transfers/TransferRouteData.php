<?php

namespace App\Data\Transfers;

use App\Enums\TransferDirection;
use App\Models\TransferRoute;
use Spatie\LaravelData\Data;

/**
 * One route on the Routes tab and in the transfer Dialog. The prices are the charge items' fixed amounts, null for
 * "No charge". Load `sharedChargeItem` and `privateChargeItem`.
 */
class TransferRouteData extends Data
{
    public function __construct(
        public int $id,
        public TransferDirection $direction,
        public string $directionLabel,
        public string $name,
        public string $place,
        public ?int $minutes,
        public ?int $sharedChargeItemId,
        public ?string $sharedChargeItemName,
        public ?string $sharedAmount,
        public ?int $privateChargeItemId,
        public ?string $privateChargeItemName,
        public ?string $privateAmount,
        public bool $active,
    ) {}

    public static function fromModel(TransferRoute $route): self
    {
        return new self(
            id: $route->id,
            direction: $route->direction,
            directionLabel: $route->direction->label(),
            name: $route->name,
            place: $route->place,
            minutes: $route->minutes,
            sharedChargeItemId: $route->shared_charge_item_id,
            sharedChargeItemName: $route->sharedChargeItem?->name,
            sharedAmount: $route->sharedChargeItem?->default_amount,
            privateChargeItemId: $route->private_charge_item_id,
            privateChargeItemName: $route->privateChargeItem?->name,
            privateAmount: $route->privateChargeItem?->default_amount,
            active: $route->active,
        );
    }
}
