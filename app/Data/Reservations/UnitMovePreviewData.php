<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Move room form shows for the first night typed so far: where the unit is on that night, the room types it
 * can move to (its own and those linked to its rate plan with the same sell unit), and the rooms or beds of the chosen
 * type that are free from that night to the departure. `sellsBeds` tells the form to label the pick Bed.
 */
class UnitMovePreviewData extends Data
{
    /**
     * @param  list<array{id: int, name: string}>  $roomTypes
     * @param  list<FreeUnitData>  $units
     */
    public function __construct(
        public ?string $current,
        public array $roomTypes,
        public ?int $roomTypeId,
        public bool $sellsBeds,
        public array $units,
        public ?string $error,
    ) {}

    public static function refused(string $error): self
    {
        return new self(null, [], null, false, [], $error);
    }
}
