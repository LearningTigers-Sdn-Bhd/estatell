<?php

namespace App\Services\Facilities;

use App\Actions\Facilities\FacilityBookingResult;
use App\Data\Facilities\EventLinesQuoteData;
use App\Data\Facilities\FacilityBookingInputData;
use App\Data\Facilities\FacilityBookingQuoteData;
use App\Data\Facilities\FacilityQuoteTaxData;
use App\Enums\EventStatus;
use App\Enums\FacilityBookingStatus;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use Brick\Math\BigDecimal;

/**
 * Quotes the facility lines of the New event page before the event exists. Each line is checked as `CreateEvent`
 * will book it: in order, counting the earlier lines as saved, so two lines that clash are caught.
 * See docs/33-facilities/03-events-and-event-folios.md#new-event-page.
 */
class EventLinesQuote
{
    public function __construct(private FacilityBookingQuote $quote, private FacilityBookingRefusals $refusals) {}

    /**
     * A line with no facility yet (`facilityId` 0) gets an empty answer. `$draft` is an unsaved event with the page's property, payer, dates and status.
     *
     * @param  list<FacilityBookingInputData>  $lines
     */
    public function quote(Event $draft, array $lines): EventLinesQuoteData
    {
        $facilities = Facility::query()
            ->where('property_id', $draft->property_id)
            ->whereKey(array_map(fn (FacilityBookingInputData $line): int => $line->facilityId, $lines))
            ->get()
            ->keyBy('id');
        $pending = [];
        $answers = [];
        $amount = BigDecimal::zero();
        $tax = BigDecimal::zero();
        $total = BigDecimal::zero();

        foreach ($lines as $line) {
            if ($line->facilityId === 0) {
                $answers[] = new FacilityBookingQuoteData(null, [], null, null, null, []);

                continue;
            }

            $facility = $facilities->get($line->facilityId);

            if ($facility === null || ! $facility->active) {
                $answers[] = new FacilityBookingQuoteData(null, [], null, $this->refusals->message(FacilityBookingResult::refused(FacilityBookingResult::REFUSAL_INACTIVE)), null, []);

                continue;
            }

            $answer = $this->quote->forEvent($draft, $facility, $line, null, $pending);
            $answers[] = $answer;

            if ($answer->amount !== null && $answer->total !== null) {
                $amount = $amount->plus($answer->amount);
                $total = $total->plus($answer->total);
                $tax = array_reduce(
                    array_filter($answer->taxes, fn (FacilityQuoteTaxData $one): bool => ! $one->inclusive),
                    fn (BigDecimal $sum, FacilityQuoteTaxData $one): BigDecimal => $sum->plus($one->amount),
                    $tax,
                );
            }

            if ($line->startsAt !== '' && $line->endsAt > $line->startsAt) {
                $pending[] = new FacilityBooking([
                    'facility_id' => $facility->id,
                    'booking_date' => $line->bookingDate,
                    'starts_at' => $line->startsAt,
                    'ends_at' => $line->endsAt,
                    'quantity' => $line->quantity,
                    'status' => $draft->status === EventStatus::Tentative ? FacilityBookingStatus::Tentative : FacilityBookingStatus::Confirmed,
                ]);
            }
        }

        return new EventLinesQuoteData(
            $answers,
            (string) $amount->toScale(2),
            (string) $tax->toScale(2),
            (string) $total->toScale(2),
        );
    }
}
