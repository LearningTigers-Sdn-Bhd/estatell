<?php

namespace App\Actions\Facilities;

use App\Actions\Folio\PostingResult;
use App\Actions\Folio\ReverseLine;
use App\Enums\FolioStatus;
use App\Models\FacilityBooking;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;

class ReverseFacilityBookingCharge
{
    public function __construct(
        private ReverseLine $reverseLine,
        private CurrentBusinessDate $currentBusinessDate,
    ) {}

    /**
     * Reverse the posted `facility:{id}` line of a booking that is being cancelled, with its tax children, through
     * `ReverseLine` (key `reverse:{line_id}`). Returns null when it is reversed or there is nothing to reverse (never
     * posted, or reversed before), else the refusal: `folio_not_open` when the folio is not Open. Staff go through
     * `ReverseLine` itself, which logs `folio.line_reversed`; a null actor (a guest cancelling online, the night audit's
     * no-show) writes the reversal as the system. Run it inside the caller's transaction, before the booking changes.
     * See docs/33-facilities/04-facility-bookings.md#posting.
     */
    public function __invoke(?User $actor, FacilityBooking $booking): ?string
    {
        $line = FolioLine::query()->where('idempotency_key', 'facility:'.$booking->id)->first();

        if ($line === null || $line->reversal()->exists()) {
            return null;
        }

        if ($actor !== null) {
            $result = ($this->reverseLine)($actor, $line);

            return $result->isRefused() ? (string) $result->refusal : null;
        }

        $folio = Folio::query()->with('property')->lockForUpdate()->findOrFail($line->folio_id);
        $businessDate = $this->currentBusinessDate->date($folio->property);

        if ($folio->status !== FolioStatus::Open) {
            return PostingResult::REFUSAL_FOLIO_NOT_OPEN;
        }

        if ($businessDate === null) {
            return PostingResult::REFUSAL_NO_BUSINESS_DATE;
        }

        $this->reverseLine->post(null, $line, $businessDate->toDateString(), null);

        return null;
    }
}
