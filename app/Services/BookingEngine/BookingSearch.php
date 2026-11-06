<?php

namespace App\Services\BookingEngine;

use App\Models\BookingClient;
use App\Models\Property;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The marketplace search: the lowest online price of each property the client reaches, for a stay and a party. A
 * property with nothing to sell is left out. It reads only.
 */
class BookingSearch
{
    public function __construct(
        private BookingClientReach $reach,
        private BookingOffers $offers,
        private BookingWindow $window,
    ) {}

    /**
     * @param  list<int>  $childAges
     * @return list<array{property: Property, lowestPrice: Money}>
     */
    public function lowestPrices(BookingClient $client, CarbonImmutable $arrival, CarbonImmutable $departure, int $adults, array $childAges): array
    {
        $results = [];

        foreach ($this->reach->properties($client) as $property) {
            if (! $this->window->contains($property, $arrival)) {
                continue;
            }

            $lowest = null;

            foreach ($this->offers->forStay($property, $arrival, $departure, $adults, $childAges, null) as $offer) {
                $total = $offer->price?->onlineTotal;

                if ($total !== null && ($lowest === null || $total->isLessThan($lowest))) {
                    $lowest = $total;
                }
            }

            if ($lowest !== null) {
                $results[] = ['property' => $property, 'lowestPrice' => $lowest];
            }
        }

        return $results;
    }
}
