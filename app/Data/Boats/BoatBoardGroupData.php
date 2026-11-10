<?php

namespace App\Data\Boats;

use App\Data\Reports\BoatManifestRow;
use Spatie\LaravelData\Data;

/**
 * One group of the Boats board: a sailing (`kind` sailing), the charter and own boat trips (`front_desk`) or the units with
 * no boat set (`not_set`). A sailing group names its boat, crew, time and seats, and carries what the group menu needs:
 * `departureId` and `sailingId` (null for the main sailing that is not stored yet), `hasStored` (the departure has stored
 * sailings on the date, so Default shows) and `canRemove` (an added boat with no guests). `taken` counts the guests of the
 * rows. See docs/24-boats/05-boats-board.md.
 */
class BoatBoardGroupData extends Data
{
    /**
     * @param  list<string>  $assistants
     * @param  list<int>  $assistantIds
     * @param  list<BoatManifestRow>  $rows
     */
    public function __construct(
        public string $key,
        public string $kind,
        public ?string $direction,
        public ?string $directionLabel,
        public ?string $time,
        public ?string $title,
        public ?string $times,
        public ?string $boat,
        public ?string $inCharge,
        public array $assistants,
        public int $taken,
        public ?int $seats,
        public ?int $departureId,
        public ?int $sailingId,
        public bool $hasStored,
        public bool $canRemove,
        public ?int $boatId,
        public ?int $inChargeId,
        public array $assistantIds,
        public array $rows,
    ) {}
}
