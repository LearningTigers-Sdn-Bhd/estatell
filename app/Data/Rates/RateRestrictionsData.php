<?php

namespace App\Data\Rates;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * The restriction columns a rate save changes. An Optional property is not sent, so its column is left alone. A null
 * value clears the column. `columns()` returns only the columns that were sent.
 */
class RateRestrictionsData extends Data
{
    public function __construct(
        public int|null|Optional $minStay = new Optional,
        public int|null|Optional $maxStay = new Optional,
        public bool|Optional $closedToArrival = new Optional,
        public bool|Optional $closedToDeparture = new Optional,
        public bool|Optional $stopSell = new Optional,
    ) {}

    /**
     * The columns that were sent, keyed by their column names.
     *
     * @return array{min_stay?: int|null, max_stay?: int|null, closed_to_arrival?: bool, closed_to_departure?: bool, stop_sell?: bool}
     */
    public function columns(): array
    {
        $columns = [];

        if (! $this->minStay instanceof Optional) {
            $columns['min_stay'] = $this->minStay;
        }

        if (! $this->maxStay instanceof Optional) {
            $columns['max_stay'] = $this->maxStay;
        }

        if (! $this->closedToArrival instanceof Optional) {
            $columns['closed_to_arrival'] = $this->closedToArrival;
        }

        if (! $this->closedToDeparture instanceof Optional) {
            $columns['closed_to_departure'] = $this->closedToDeparture;
        }

        if (! $this->stopSell instanceof Optional) {
            $columns['stop_sell'] = $this->stopSell;
        }

        return $columns;
    }
}
