<?php

namespace App\Actions\Facilities;

use App\Actions\Folio\PostFolioCharge;
use App\Actions\Folio\PostingResult;
use App\Actions\NightAudit\ResolveFacilityBookingExceptions;
use App\Enums\FacilityBookingStatus;
use App\Enums\FolioStatus;
use App\Enums\TransactionKind;
use App\Models\FacilityBooking;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\User;
use App\Services\Facilities\FacilityBookingCharge;
use App\Services\Folio\FolioPostability;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;

class PostFacilityBooking
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FacilityBookingCharge $charge,
        private FolioPostability $folioPostability,
        private PostFolioCharge $postFolioCharge,
        private ResolveFacilityBookingExceptions $resolveExceptions,
    ) {}

    /**
     * Post one Confirmed booking to its folio on the business date: one charge line with its tax children, the key
     * `facility:{id}`, the facility's transaction code and the booking as the source. `service_date` is the booking date,
     * or the business date when Post now runs before the booking date (a line never serves after it posts); the
     * description then adds the booking date. Post now and the night audit both call it; a null actor is the night audit. The booking row is locked
     * first and the folio next, so two clicks post once, and a repeat returns the existing lines as replayed. Refuses a
     * Cancelled booking (`facility_booking_cancelled`), a Tentative one (`facility_booking_tentative`), one charged by
     * hand (`facility_charged_by_hand`), a folio that takes no charges (`folio_not_open`, `folio_settled_to_city_ledger`)
     * and a tax that cannot be worked out. A refusal writes nothing. Once posted, the booking's open `Facility` audit
     * exceptions are resolved. See docs/33-facilities/04-facility-bookings.md#posting.
     */
    public function __invoke(?User $actor, FacilityBooking $booking): PostingResult
    {
        return DB::transaction(function () use ($actor, $booking): PostingResult {
            $current = FacilityBooking::query()->lockForUpdate()->findOrFail($booking->id);
            $key = 'facility:'.$current->id;
            $existing = FolioLine::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return PostingResult::replayed([$existing, ...$existing->children()->orderBy('id')->get()->all()]);
            }

            $refusal = match (true) {
                $current->status === FacilityBookingStatus::Cancelled => FacilityBookingResult::REFUSAL_BOOKING_CANCELLED,
                $current->status === FacilityBookingStatus::Tentative => FacilityBookingResult::REFUSAL_TENTATIVE,
                $current->unit_amount === null => FacilityBookingResult::REFUSAL_CHARGED_BY_HAND,
                default => null,
            };

            if ($refusal !== null) {
                return PostingResult::refused($refusal);
            }

            $property = Property::query()->with('settings')->findOrFail($current->property_id);
            $businessDate = $this->currentBusinessDate->date($property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $folio = Folio::query()->lockForUpdate()->findOrFail($current->folio_id);

            if ($folio->status !== FolioStatus::Open) {
                return PostingResult::refused((string) $this->folioPostability->refusalFor($folio));
            }

            $current->load(FacilityBookingCharge::relations());
            $charge = $this->charge->for($property, $current);

            if ($charge === null || $charge['code']->kind !== TransactionKind::Charge) {
                return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            if (! $charge['tax']->isCalculated()) {
                return PostingResult::refused((string) $charge['tax']->refusalReason);
            }

            $postsEarly = $current->booking_date->isAfter($businessDate);
            $lines = ($this->postFolioCharge)(
                $actor,
                $folio,
                $charge['code'],
                $charge['description'].($postsEarly ? ' · '.$current->booking_date->format('j M') : ''),
                $charge['quantity'],
                $charge['unitAmount'],
                $charge['tax'],
                $key,
                $businessDate->toDateString(),
                $current,
                serviceDate: ($postsEarly ? $businessDate : $current->booking_date)->toDateString(),
            );
            ($this->resolveExceptions)($actor, $current);

            return PostingResult::posted($lines);
        });
    }
}
