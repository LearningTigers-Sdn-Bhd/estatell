<?php

namespace App\Actions\FrontDesk;

use App\Actions\NightAudit\PostMissedNights;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExtendStay
{
    public function __construct(
        private ChangeUnitDates $changeUnitDates,
        private PostMissedNights $postMissedNights,
    ) {}

    /**
     * Move the departure of a unit to a later date and keep its arrival: `ChangeUnitDates` with the arrival locked.
     * A guest already in the house also gets any added night before the business date posted now, because the audit only
     * posts forward: a guest due on 5 Nov who is extended after the audit passed 5 Nov still pays for that night.
     * A date that is not later is refused with `not_an_extension`, writing nothing. The night audit's readiness fix
     * "Extend stay" calls this. The arguments after the date are those of `ChangeUnitDates`, and an extension never
     * has a fee. Permissions are checked by the route. See docs/18-front-desk/01-check-in-and-check-out.md.
     */
    public function __invoke(?User $actor, ReservationUnit $unit, CarbonInterface $newDeparture, ?string $restrictionOverrideReason = null): StayDatesResult
    {
        if ($newDeparture->startOfDay()->lte($unit->departure_date->startOfDay())) {
            return StayDatesResult::refused(UnitStayResult::REFUSAL_NOT_AN_EXTENSION);
        }

        DB::beginTransaction();

        try {
            $result = ($this->changeUnitDates)($actor, $unit, $unit->arrival_date, $newDeparture, restrictionOverrideReason: $restrictionOverrideReason);

            if (! $result->isRefused() && $unit->fresh()?->status === UnitStatus::CheckedIn) {
                $missed = ($this->postMissedNights)($actor, $unit->fresh());

                if ($missed->isRefused()) {
                    DB::rollBack();

                    return StayDatesResult::refused((string) $missed->refusal);
                }
            }
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $result->isRefused() ? DB::rollBack() : DB::commit();

        return $result;
    }
}
