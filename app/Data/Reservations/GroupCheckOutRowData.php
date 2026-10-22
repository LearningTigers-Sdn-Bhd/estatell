<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One room of the group check-out page. `group` is the section: `ready` (Ready to check out), `money` (Money to settle),
 * `later` (Departing later) or `blocked` (Can't check out yet). A row that is not `selectable` is never touched and `note` says why. `choices` are the settle choices the user may
 * make (`collect`, `refund`, `move`) and `defaultChoice` the one prefilled by balance. `moveAmount` is what Add to Master
 * moves and `refundOnMove` what it refunds from the payments the folio holds. `incoming` names the rooms that still
 * charge their Scheduled nights to this room's folio, and `canMoveCharges` says the user may send them elsewhere
 * (`folio.transfer`). `unit` is the room as the reservation page sends it, only for a room that departs later, for the
 * **Check out** Dialog opened by **Check out early…**. `checkedOutOn` is the date a room that is out was checked out
 * (`Y-m-d`, property time zone), null for any other room. `pendingLines` and `pendingTotal` are the lines the check-out
 * posts first for the night of the business date while the night audit is pending (shown as added now); the balance and
 * the settle amounts already include them.
 */
class GroupCheckOutRowData extends Data
{
    /**
     * @param  list<string>  $choices
     * @param  list<string>  $incoming
     * @param  list<UnitPendingLineData>  $pendingLines
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
        public ?string $checkedOutOn,
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
        public bool $canMoveCharges,
        public ?ReservationUnitData $unit,
        public array $pendingLines = [],
        public string $pendingTotal = '0.00',
    ) {}
}
