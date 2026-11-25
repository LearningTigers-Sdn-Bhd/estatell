<?php

namespace App\Data\Transfers;

use App\Enums\TransferDirection;
use App\Enums\TransferMode;
use Spatie\LaravelData\Data;

/**
 * What staff set for one unit and direction: how it is charged, the route, the pick-up time (`H:i`), and an optional
 * flight number and note.
 */
class UnitTransferInputData extends Data
{
    public function __construct(
        public TransferDirection $direction,
        public TransferMode $mode,
        public int $routeId,
        public string $pickupTime,
        public ?string $flightNumber = null,
        public ?string $note = null,
        public ?int $bags = null,
    ) {}
}
