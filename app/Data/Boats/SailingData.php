<?php

namespace App\Data\Boats;

use App\Enums\BoatDirection;
use Spatie\LaravelData\Data;

/**
 * What staff set on a sailing: the boat, one person in charge and the assistants. Times (`H:i`) are only changed when
 * `setsTimes` is true; then both are given, or both are null to go back to the timetable. `SaveSailing` and
 * `AddSailingBoat` check them. `direction` and `pickupPoint` are for an extra trip (`AddExtraSailing`), which has no departure.
 */
class SailingData extends Data
{
    /**
     * @param  list<int>  $assistantIds
     */
    public function __construct(
        public ?int $boatId = null,
        public ?int $inChargeId = null,
        public array $assistantIds = [],
        public bool $setsTimes = false,
        public ?string $leavesAt = null,
        public ?string $arrivesAt = null,
        public ?BoatDirection $direction = null,
        public ?string $pickupPoint = null,
    ) {}

    /**
     * The assistants without duplicates and without the person in charge.
     *
     * @return list<int>
     */
    public function assistants(): array
    {
        return collect($this->assistantIds)
            ->map(intval(...))
            ->reject(fn (int $id): bool => $id === $this->inChargeId)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
