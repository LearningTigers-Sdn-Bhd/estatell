<?php

namespace App\Data\Rooms;

use App\Models\Bed;
use Spatie\LaravelData\Data;

/**
 * One bed of a dorm room, and the values of its edit form.
 */
class BedData extends Data
{
    public function __construct(
        public int $id,
        public int $roomId,
        public string $label,
        public bool $archived,
    ) {}

    public static function fromModel(Bed $bed): self
    {
        return new self(
            id: $bed->id,
            roomId: $bed->room_id,
            label: $bed->label,
            archived: $bed->archived_at !== null,
        );
    }
}
