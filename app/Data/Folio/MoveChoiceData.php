<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One scheduled choice of a room: the title ("Charge room nights to Master · Acme"), the detail line and the amount
 * of the Scheduled rows it moves. `scope` is the value the form sends back (`room` or `all`).
 */
class MoveChoiceData extends Data
{
    public function __construct(
        public string $scope,
        public string $title,
        public string $detail,
        public string $amount,
    ) {}
}
