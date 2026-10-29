<?php

namespace App\Data\Rooms;

use Spatie\LaravelData\Data;

/**
 * What a bulk room action did: how many rooms changed, and the refusal for each room it left as it was.
 */
class RoomBulkResultData extends Data
{
    /**
     * @param  list<string>  $refused  One message per refused room, for example "301 has beds.".
     */
    public function __construct(
        public int $done,
        public array $refused,
    ) {}
}
