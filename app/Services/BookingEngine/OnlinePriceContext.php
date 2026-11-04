<?php

namespace App\Services\BookingEngine;

use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\Tax;
use App\Models\TransactionCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What `OnlineStayPrice` reads once for all the offers of a property.
 */
class OnlinePriceContext
{
    /**
     * @param  Collection<int, Tax>  $taxes  With the ids of their transaction codes.
     * @param  list<int>  $flaggedTaxIds
     * @param  list<ChargeItem>  $autoAddOns
     */
    public function __construct(
        public readonly Property $property,
        public readonly ?TransactionCode $roomCode,
        public readonly Collection $taxes,
        public readonly array $flaggedTaxIds,
        public readonly array $autoAddOns,
        public readonly CarbonImmutable $businessDate,
    ) {}
}
