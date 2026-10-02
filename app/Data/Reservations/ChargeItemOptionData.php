<?php

namespace App\Data\Reservations;

use App\Models\ChargeItem;
use Spatie\LaravelData\Data;

/**
 * A charge item to pick in the Add add-on Dialog. `defaultAmount` is a decimal string, null when staff must enter one.
 */
class ChargeItemOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $chargingUnit,
        public string $chargingUnitLabel,
        public ?string $defaultAmount,
        public bool $allowOverride,
    ) {}

    public static function fromModel(ChargeItem $item): self
    {
        return new self($item->id, $item->name, $item->charging_unit->value, $item->charging_unit->label(), $item->default_amount, $item->allow_override);
    }
}
