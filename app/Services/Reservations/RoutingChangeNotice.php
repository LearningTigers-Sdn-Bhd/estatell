<?php

namespace App\Services\Reservations;

use App\Models\Reservation;
use App\Services\Folio\MasterPaymentsHeld;

/**
 * The toast after a change that can start sending room charges to the Master folio (add a unit, change the billing
 * mode, change the company). It is the success message alone, or a warning that also names what stayed on the units'
 * own folios: posted room lines, and Advance money that can no longer meet its room charges. A payment line never
 * moves, so the warning tells staff to transfer the room charges back or refund the advance. It reads only. See
 * docs/13-folio/03-routing.md#billing-mode-rules.
 */
class RoutingChangeNotice
{
    public function __construct(private MasterPaymentsHeld $masterPaymentsHeld) {}

    /**
     * @param  list<int>  $unitsWithPostedRoomLines
     * @param  array<int, string>  $unitAdvances  Amount by unit id
     * @return array{type: string, message: string}
     */
    public function toast(string $saved, Reservation $reservation, array $unitsWithPostedRoomLines, array $unitAdvances): array
    {
        $parts = [$saved];

        if ($unitAdvances !== []) {
            $amount = $this->masterPaymentsHeld->label($reservation, array_reduce($unitAdvances, fn (string $total, string $held): string => bcadd($total, $held, 2), '0.00'));

            $parts[] = count($unitAdvances) === 1
                ? __("Room charges now go to the Master folio, but a unit's own folio holds a :amount advance. Transfer its room charges back to it, or refund the advance.", ['amount' => $amount])
                : __("Room charges now go to the Master folio, but :count units' own folios hold :amount in advances. Transfer their room charges back to them, or refund the advances.", ['count' => count($unitAdvances), 'amount' => $amount]);
        }

        if ($unitsWithPostedRoomLines !== []) {
            $parts[] = count($unitsWithPostedRoomLines) === 1
                ? __('One unit keeps its posted room charges on its own folio. Later nights go to the Master folio.')
                : __(':count units keep their posted room charges on their own folios. Later nights go to the Master folio.', ['count' => count($unitsWithPostedRoomLines)]);
        }

        return [
            'type' => count($parts) > 1 ? 'warning' : 'success',
            'message' => implode(' ', $parts),
        ];
    }
}
