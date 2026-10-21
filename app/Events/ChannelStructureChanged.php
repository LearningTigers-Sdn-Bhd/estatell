<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A room type, a rate plan or the room types a plan sells changed in a way the channel manager's structure shows: a
 * name, the adults, how a plan is priced, a link. The channels module sends the structure again a short while after
 * the last such change. Prices, restrictions and availability are separate (`RatesChanged`, `InventoryChanged`).
 */
class ChannelStructureChanged
{
    use Dispatchable;

    public function __construct(public readonly int $propertyId) {}
}
