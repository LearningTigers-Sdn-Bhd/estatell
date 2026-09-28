<?php

namespace App\Data\Rooms;

use App\Models\RoomGroup;
use Spatie\LaravelData\Data;

/**
 * One room group in the rooms page.
 */
class RoomGroupData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public static function fromModel(RoomGroup $group): self
    {
        return new self(id: $group->id, name: $group->name);
    }
}
