<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * A Timeline row a unit can be dragged to: `rowKey` is `room-{id}` or `bed-{id}`, as in the Stay View, and `freeFrom`
 * is the first night from which it is free to the departure. A drop on an earlier night is not allowed.
 */
class MoveTargetData extends Data
{
    public function __construct(
        public string $rowKey,
        public int $roomTypeId,
        public string $freeFrom,
    ) {}
}
