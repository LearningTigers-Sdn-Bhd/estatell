<?php

namespace App\Data\Housekeeping;

use Spatie\LaravelData\Data;

/**
 * The cleaning cell of a room on the board and on House status: the latest cleaning of the date (or one still open
 * from an earlier date), and what the viewer may do. The server decides, so the browser holds no rules.
 */
class RoomCleaningStateData extends Data
{
    public function __construct(
        public int $roomId,
        public string $roomNumber,
        public ?RoomCleaningData $cleaning,
        public bool $canStart,
        public bool $canFinish,
        public bool $canEdit,
    ) {}
}
