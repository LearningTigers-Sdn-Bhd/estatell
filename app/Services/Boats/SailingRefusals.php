<?php

namespace App\Services\Boats;

use App\Actions\Boats\SailingResult;
use App\Services\Reservations\ReservationRefusals;

/**
 * The words of a refused sailing change, for the error toast. Reads only.
 */
class SailingRefusals
{
    public function __construct(private ReservationRefusals $refusals) {}

    public function message(SailingResult $result): string
    {
        return match ($result->refusal) {
            SailingResult::REFUSAL_SAILING_HAS_GUESTS => trans_choice('Move the :count guest first.|Move the :count guests first.', $result->guests),
            SailingResult::REFUSAL_BOAT_UNAVAILABLE => __('Pick an available boat and crew.'),
            SailingResult::REFUSAL_IN_CHARGE_REQUIRED => __('Pick who is in charge first.'),
            SailingResult::REFUSAL_DATE_CLOSED => __('This date is closed. Past dates are read only.'),
            SailingResult::REFUSAL_SAILING_IS_MAIN => __('The first boat of a departure cannot be removed. Use Default to go back to the timetable.'),
            SailingResult::REFUSAL_TIMES_INVALID => __('Arrives must be after Leaves.'),
            SailingResult::REFUSAL_TARGET_MISMATCH => __('Guests can only move to a boat on the same date and direction.'),
            SailingResult::REFUSAL_JOB_DONE => __('This job is done. Untick Done first.'),
            SailingResult::REFUSAL_SAILING_NOT_FOUND => __('That boat is no longer there. Reload the page.'),
            default => $result->unitLabel !== null
                ? $result->unitLabel.': '.current($this->refusals->boatTripErrors((string) $result->refusal))
                : __('The change could not be saved.'),
        };
    }
}
