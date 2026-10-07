<?php

namespace App\Actions\NightAudit;

use App\Actions\FrontDesk\AutoAssignRooms;
use App\Actions\FrontDesk\ExtendStay;
use App\Actions\FrontDesk\MarkNoShow;
use App\Actions\NightAudit\Steps\ResolveOverdueDepartures;
use App\Enums\AuditFix;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\NightAudit\AuditReadiness;
use App\Services\NightAudit\CurrentBusinessDate;

class RunAuditFix
{
    public function __construct(
        private AuditReadiness $auditReadiness,
        private CurrentBusinessDate $currentBusinessDate,
        private AutoAssignRooms $autoAssignRooms,
        private MarkNoShow $markNoShow,
        private ExtendStay $extendStay,
    ) {}

    /**
     * Run one fix of the night audit sheet on the units of one reservation that the sheet lists for it, or on one of
     * them. Only listed units are touched, so a unit that changed since the sheet loaded is skipped and a unit of
     * another reservation is never reached. Each unit goes through the Action of the front desk with the user as
     * actor: Assign gives a free room for the whole stay, No-show marks the unit with the fee of the policy, and
     * Extend moves a unit that departs on the business date one night later. A unit an Action refuses is returned
     * with its refusal code and the others still run. Permissions are checked by the route.
     */
    public function __invoke(User $actor, Property $property, Reservation $reservation, AuditFix $fix, ?int $unitId = null): AuditFixResult
    {
        $date = $this->currentBusinessDate->date($property);

        if ($date === null) {
            return new AuditFixResult(0, []);
        }

        $ids = collect($this->auditReadiness->items($property))
            ->filter(fn ($item): bool => $item->check === $fix->check() && $item->reservationId === $reservation->id && ($unitId === null || $item->reservationUnitId === $unitId))
            ->pluck('reservationUnitId')
            ->unique();
        $done = 0;
        $refusals = [];

        foreach (ReservationUnit::query()->whereKey($ids)->orderBy('id')->get() as $unit) {
            $refusal = match ($fix) {
                AuditFix::Assign => ($this->autoAssignRooms)($actor, $unit)->refusal,
                AuditFix::NoShow => ($this->markNoShow)($actor, $unit)->refusal,
                AuditFix::ExtendOneNight => $unit->departure_date->toDateString() === $date->toDateString()
                    ? ($this->extendStay)($actor, $unit, $date->addDay())->refusal
                    : ResolveOverdueDepartures::MESSAGE_PAST_DEPARTURE,
            };

            if ($refusal === null) {
                $done++;
            } else {
                $refusals[$unit->id] = $refusal;
            }
        }

        return new AuditFixResult($done, $refusals);
    }
}
