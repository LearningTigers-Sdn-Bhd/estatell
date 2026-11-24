<?php

namespace App\Data\Pos;

use App\Models\PosOutlet;
use Spatie\LaravelData\Data;

/**
 * An outlet as the outlets settings page lists it.
 */
class PosOutletRowData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $code,
        public ?int $defaultChargeItemId,
        public ?string $chargeItemName,
        public bool $active,
        public bool $nativePosEnabled,
    ) {}

    public static function fromModel(PosOutlet $outlet): self
    {
        return new self($outlet->id, $outlet->name, $outlet->code, $outlet->default_charge_item_id, $outlet->defaultChargeItem?->name, $outlet->active, $outlet->native_pos_enabled);
    }
}
