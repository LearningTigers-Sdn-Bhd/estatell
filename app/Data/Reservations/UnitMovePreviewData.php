<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Move room form shows for the first night typed so far: where the unit is on that night, the room types it
 * can move to (its own and those linked to its rate plan with the same sell unit), and the rooms or beds of the chosen
 * type that are free from that night to the departure. `sellsBeds` tells the form to label the pick Bed. `reasonRequired` is false when the night has no room or bed yet.
 * For a day use unit `isDayUse` is true, `hoursLabel` reads "Day use · 10:00–14:00", `roomTypes` holds only its own type and `units` are the rooms free for those hours (the night is ignored).
 * `targets` is filled only when the Timeline asks for it at the start of a drag: every room or bed of every room type the unit can move to, with the first night it is free from.
 */
class UnitMovePreviewData extends Data
{
    /**
     * @param  list<array{id: int, name: string}>  $roomTypes
     * @param  list<FreeUnitData>  $units
     * @param  list<MoveTargetData>  $targets
     */
    public function __construct(
        public ?string $current,
        public array $roomTypes,
        public ?int $roomTypeId,
        public bool $sellsBeds,
        public bool $reasonRequired,
        public array $units,
        public ?string $error,
        public bool $isDayUse = false,
        public ?string $hoursLabel = null,
        public array $targets = [],
    ) {}

    public static function refused(string $error): self
    {
        return new self(null, [], null, false, true, [], $error);
    }
}
