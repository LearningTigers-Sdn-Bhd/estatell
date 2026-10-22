<?php

namespace App\Services\FrontDesk;

use App\Actions\FrontDesk\UnitStayResult;
use App\Enums\UnitStatus;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;

/**
 * When a unit can check out before its departure date: it is CheckedIn, its departure is after the stay day (`StayDay`:
 * the calendar date while the night audit is pending, otherwise the business date) and the new departure, the stay
 * day, is after its arrival date. A unit that arrived on the stay day has no night to keep, because moving its departure
 * to that day would end the stay where it began. While the audit is pending, a unit that arrived on the business date
 * can still leave early: the night of the business date stays and posts first (`PostPendingNight`). An Overdue audit
 * (the calendar date 2 or more days after the business date) refuses `night_audit_pending`. The Guest actually left
 * yesterday choice (`$leftYesterday`) moves the departure to the business date instead: it needs the audit pending, a
 * departure after the business date and an arrival before it. The unit menu, the group check-out page, the preview and
 * `EarlyCheckOut` all ask this one class, so they never disagree. It reads only.
 * See docs/18-front-desk/01-check-in-and-check-out.md#early-check-out.
 */
class EarlyCheckOutRule
{
    public function __construct(private StayDay $stayDay) {}

    /**
     * The refusal code, or null when the unit can check out early.
     *
     * @param  string  $businessDate  `Y-m-d`
     */
    public function refusal(ReservationUnit $unit, string $businessDate, bool $leftYesterday = false): ?string
    {
        if ($unit->status !== UnitStatus::CheckedIn) {
            return UnitStayResult::REFUSAL_INVALID_STATUS;
        }

        $property = $unit->loadMissing('property.settings')->property;
        $business = CarbonImmutable::parse($businessDate);
        $pending = $this->stayDay->isAuditPending($property, $business);

        if ($leftYesterday && ! $pending) {
            return UnitStayResult::REFUSAL_LEFT_YESTERDAY_UNAVAILABLE;
        }

        if ($leftYesterday && $this->isNightPosted($unit, $businessDate)) {
            return UnitStayResult::REFUSAL_LEFT_YESTERDAY_UNAVAILABLE;
        }

        $newDeparture = $this->newDeparture($property, $business, $leftYesterday)->toDateString();

        if ($leftYesterday ? $businessDate >= $unit->departure_date->toDateString() : $newDeparture >= $unit->departure_date->toDateString()) {
            return UnitStayResult::REFUSAL_NOT_EARLY;
        }

        if ($newDeparture <= $unit->arrival_date->toDateString()) {
            return UnitStayResult::REFUSAL_ARRIVED_TODAY;
        }

        if ($this->stayDay->isAuditOverdue($property, $business)) {
            return UnitStayResult::REFUSAL_NIGHT_AUDIT_PENDING;
        }

        return null;
    }

    /**
     * Whether the night of the business date is already charged (the audit posted it), so it cannot be removed.
     */
    private function isNightPosted(ReservationUnit $unit, string $businessDate): bool
    {
        $nightId = $unit->nights()->whereDate('date', $businessDate)->value('id');

        return $nightId !== null && FolioLine::query()->where('idempotency_key', 'night:'.$nightId)->exists();
    }

    /**
     * @param  string  $businessDate  `Y-m-d`
     */
    public function isOffered(ReservationUnit $unit, string $businessDate, bool $leftYesterday = false): bool
    {
        return $this->refusal($unit, $businessDate, $leftYesterday) === null;
    }

    /**
     * The departure the unit gets: the stay day, or the business date for Guest actually left yesterday.
     */
    public function newDeparture(Property $property, CarbonImmutable $businessDate, bool $leftYesterday = false): CarbonImmutable
    {
        return $leftYesterday ? $businessDate : $this->stayDay->forCheckOut($property, $businessDate);
    }
}
