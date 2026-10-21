<?php

namespace App\Data\Channels;

use App\Models\ChannelRoomTypeMapping;
use Spatie\LaravelData\Data;

/**
 * A room type at Channex with its rate plans, for the Remove from Channex list.
 */
class ChannelSyncedRoomTypeData extends Data
{
    /**
     * @param  list<array{id: int, name: string}>  $ratePlans
     */
    public function __construct(
        public int $mappingId,
        public string $name,
        public array $ratePlans,
    ) {}

    /**
     * Expects `roomType` loaded, and `ratePlanMappings` of the room type loaded as `ratePlans` with `ratePlan`.
     *
     * @param  list<array{id: int, name: string}>  $ratePlans
     */
    public static function fromMapping(ChannelRoomTypeMapping $mapping, array $ratePlans): self
    {
        return new self($mapping->id, $mapping->roomType->name, $ratePlans);
    }
}
