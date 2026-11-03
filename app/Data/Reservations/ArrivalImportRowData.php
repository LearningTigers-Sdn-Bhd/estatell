<?php

namespace App\Data\Reservations;

use App\Data\Shared\BadgeData;
use App\Enums\ArrivalImportOutcome;
use Spatie\LaravelData\Data;

/**
 * One planned row of an arrival import: what the file says, what it matched and what it becomes. `problems` blocks the
 * import. Dates are `Y-m-d`, times `H:i`, money decimal strings. See docs/12-reservations/13-arrival-import.md.
 */
class ArrivalImportRowData extends Data
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(
        public int $line,
        public string $resNo,
        public string $groupNo,
        public string $guest,
        public ?string $room,
        public ?string $roomType,
        public ?string $arrival,
        public ?string $arrivalTime,
        public ?string $departure,
        public ?string $departureTime,
        public int $adults,
        public int $children,
        public ?string $total,
        public ?string $deposit,
        public ?string $company,
        public ?string $source,
        public ?string $boatIn,
        public ?string $boatOut,
        public ?string $notes,
        public ArrivalImportOutcome $outcome,
        public BadgeData $outcomeBadge,
        public array $problems,
        public ?int $roomTypeId = null,
        public ?int $roomId = null,
        public ?int $ratePlanId = null,
        public ?int $companyId = null,
        public ?int $bookingSourceId = null,
        public ?int $boatInDepartureId = null,
        public ?int $boatOutDepartureId = null,
    ) {}

    public function imports(): bool
    {
        return $this->outcome->imports();
    }
}
