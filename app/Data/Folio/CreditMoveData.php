<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * What **Move credit…** offers on an Open folio in credit: the `credit` it holds and the other Open folios of the
 * reservation that owe money. See docs/13-folio/02-posting-charges.md#move-credit.
 */
class CreditMoveData extends Data
{
    /**
     * @param  list<CreditMoveTargetData>  $targets
     */
    public function __construct(
        public string $credit,
        public array $targets,
    ) {}
}
