<?php

namespace App\Actions\Concierge;

use App\Actions\FrontDesk\CheckInUnit;
use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Kiosk\KioskCheckInResult;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Services\Concierge\ConciergeStays;
use App\Services\Kiosk\KioskEligibility;
use App\Services\Kiosk\KioskStays;
use Illuminate\Support\Facades\DB;

class CheckInConciergeStay
{
    /** Wastays used 50 m; a phone indoors can be off by more, so a property that finds this strict turns the check off. */
    public const int MAX_DISTANCE_METRES = 50;

    public function __construct(private KioskEligibility $eligibility, private CheckInUnit $checkInUnit, private KioskStays $stays, private ConciergeStays $concierge) {}

    /**
     * Check the guest in with the front desk's own `CheckInUnit`, with no staff actor. The rules are the front desk's
     * (card, nationality, room for tonight, arrival) and the paid-enough rule comes first, so nothing is written for a
     * guest who still owes the deposit. A stay already checked in answers the same room again, so a retry is safe.
     * The page never skips the registration card and never overrides a credit limit.
     *
     * Refusals are `card_required_for_check_in`, `deposit_not_paid`, `nationality_required`, `arrival_not_reached`, `check_in_time_not_reached`, `location_required`, `too_far_away`,
     * and `server_error` for everything the guest cannot fix (no room ready, credit limit, no business date).
     * `concierge.checked_in` holds the property id only, never guest data.
     */
    public function __invoke(ReservationUnit $stay, ?float $latitude = null, ?float $longitude = null): KioskCheckInResult
    {
        if ($stay->status === UnitStatus::CheckedIn) {
            return KioskCheckInResult::done($this->stays->roomNumber($stay));
        }

        if (! $this->concierge->checkInTimeReached($stay)) {
            return KioskCheckInResult::refused('check_in_time_not_reached');
        }

        $refusal = $this->locationRefusal($stay, $latitude, $longitude);

        if ($refusal !== null) {
            return KioskCheckInResult::refused($refusal);
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

    /**
     * When the property asks guests to be at the property to check in and has a location, the guest's position must be
     * given and be within `MAX_DISTANCE_METRES` of it. A property with the switch off, or with no location, asks nothing.
     */
    private function locationRefusal(ReservationUnit $stay, ?float $latitude, ?float $longitude): ?string
    {
        $property = $stay->property;

        if (! $property->settings->concierge_location_check_enabled || blank($property->latitude) || blank($property->longitude)) {
            return null;
        }

        if ($latitude === null || $longitude === null) {
            return 'location_required';
        }

        return $this->metresBetween($latitude, $longitude, (float) $property->latitude, (float) $property->longitude) > self::MAX_DISTANCE_METRES ? 'too_far_away' : null;
    }

    private function metresBetween(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
