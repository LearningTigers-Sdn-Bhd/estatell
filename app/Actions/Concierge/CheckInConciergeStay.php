<?php

namespace App\Actions\Concierge;

use App\Actions\FrontDesk\CheckInUnit;
use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Kiosk\KioskCheckInResult;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskEligibility;
use App\Services\Kiosk\KioskStays;
use Illuminate\Support\Facades\DB;

class CheckInConciergeStay
{
    public function __construct(private KioskEligibility $eligibility, private CheckInUnit $checkInUnit, private KioskStays $stays) {}

    /**
     * Check the guest in with the front desk's own `CheckInUnit`, with no staff actor. The rules are the front desk's
     * (card, nationality, room for tonight, arrival) and the paid-enough rule comes first, so nothing is written for a
     * guest who still owes the deposit. A stay already checked in answers the same room again, so a retry is safe.
     * The page never skips the registration card and never overrides a credit limit.
     *
     * Refusals are `card_required_for_check_in`, `deposit_not_paid`, `nationality_required`, `arrival_not_reached`,
     * and `server_error` for everything the guest cannot fix (no room ready, credit limit, no business date).
     * `concierge.checked_in` holds the property id only, never guest data.
     */
    public function __invoke(ReservationUnit $stay): KioskCheckInResult
    {
        if ($stay->status === UnitStatus::CheckedIn) {
            return KioskCheckInResult::done($this->stays->roomNumber($stay));
        }

        $refusal = DB::transaction(function () use ($stay): ?string {
            $stay = ReservationUnit::query()->where('property_id', $stay->property_id)->lockForUpdate()->findOrFail($stay->id);
            $reason = $this->eligibility->reason($stay);

            if ($reason !== null) {
                return $reason;
            }

            $result = ($this->checkInUnit)(null, $stay);

            if ($result->isRefused()) {
                return match ($result->refusal) {
                    UnitStayResult::REFUSAL_CARD_MISSING => 'card_required_for_check_in',
                    UnitStayResult::REFUSAL_NATIONALITY_REQUIRED => 'nationality_required',
                    UnitStayResult::REFUSAL_ARRIVAL_NOT_REACHED => 'arrival_not_reached',
                    default => 'server_error',
                };
            }

            activity()
                ->performedOn($stay)
                ->event('concierge.checked_in')
                ->withProperties(['property_id' => $stay->property_id])
                ->log('Checked in on the concierge page');

            return null;
        });

        if ($refusal !== null) {
            return KioskCheckInResult::refused($refusal);
        }

        $stay->refresh();

        return KioskCheckInResult::done($this->stays->roomNumber($stay));
    }
}
