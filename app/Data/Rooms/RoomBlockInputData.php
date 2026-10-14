<?php

namespace App\Data\Rooms;

use App\Enums\RoomBlockReason;
use Spatie\LaravelData\Data;

/**
 * The fields of a room block typed in its form. Dates are `Y-m-d`; both nights are blocked. `notes` is trimmed, and
 * null when empty. The room and bed are not part of it: the Actions take them as their own arguments.
 */
class RoomBlockInputData extends Data
{
    public function __construct(
        public RoomBlockReason $reason,
        public string $startsOn,
        public string $endsOn,
        public ?string $notes,
    ) {}
}
