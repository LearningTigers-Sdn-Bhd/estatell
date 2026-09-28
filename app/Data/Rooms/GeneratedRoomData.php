<?php

namespace App\Data\Rooms;

use Spatie\LaravelData\Data;

/**
 * One line of the room generator preview.
 */
class GeneratedRoomData extends Data
{
    public function __construct(
        public string $number,
        public ?string $group,
        public bool $taken,
    ) {}

    /**
     * @param  array{number: string, group: string|null}  $entry
     */
    public static function fromPlanEntry(array $entry, bool $taken): self
    {
        return new self(number: $entry['number'], group: $entry['group'], taken: $taken);
    }
}
