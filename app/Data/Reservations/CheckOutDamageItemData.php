<?php

namespace App\Data\Reservations;

use App\Models\ChargeItem;
use Spatie\LaravelData\Data;

/**
 * The property's damage charge item, offered in the check-out Dialog. `defaultAmount` is a decimal string, null when
 * staff type the amount. `allowOverride` false means only the default amount posts.
 */
class CheckOutDamageItemData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $defaultAmount,
        public bool $allowOverride,
    ) {}

    public static function fromModel(ChargeItem $item): self
    {
        return new self($item->id, $item->name, $item->default_amount, $item->allow_override);
    }
}
