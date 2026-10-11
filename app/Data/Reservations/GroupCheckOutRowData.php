<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One room of the group check-out page. `group` is `main`, `out` (already checked out), `cancelled` or `not_arrived`.
 * A row that is not `selectable` is never touched and `note` says why. `choices` are the settle choices the user may
 * make (`collect`, `refund`, `move`) and `defaultChoice` the one prefilled by balance. `moveAmount` is what Add to Master
 * moves and `refundOnMove` what it refunds from the payments the folio holds. `incoming` names the rooms that still
 * charge their Scheduled nights to this room's folio.
 */
class GroupCheckOutRowData extends Data
{
    /**
     * @param  list<string>  $choices
     * @param  list<string>  $incoming
     */
    public function __construct(
        public int $unitId,
        public string $label,
        public ?string $guest,
        public string $status,
        public string $group,
        public bool $ticked,
        public bool $selectable,
        public ?string $note,
        public string $departure,
        public string $balance,
        public string $collectAmount,
        public string $refundAmount,
        public string $moveAmount,
        public string $refundOnMove,
        public ?string $moveNote,
        public array $choices,
        public ?string $defaultChoice,
        public ?int $methodId,
        public array $incoming,
        public bool $folioClosed,
        public ?int $folioId,
        public bool $canReopen,
    ) {}
}
