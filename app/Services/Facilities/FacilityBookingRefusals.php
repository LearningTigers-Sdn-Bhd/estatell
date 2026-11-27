<?php

namespace App\Services\Facilities;

use App\Actions\Facilities\FacilityBookingResult;
use App\Actions\Folio\PostingResult;

/**
 * Turns facility booking refusals into a message and the Book facility field it belongs to. A refusal with no field
 * uses `form`. See docs/33-facilities/04-facility-bookings.md#actions.
 */
class FacilityBookingRefusals
{
    public const string FORM = 'form';

    /**
     * The errors to flash back to the Sheet, keyed by field name.
     *
     * @return array<string, string>
     */
    public function errors(FacilityBookingResult $result): array
    {
        return [$this->field((string) $result->refusal) => $this->message($result)];
    }

    /**
     * The error for one facility line of the New event page, keyed `bookings.{line}.{field}`. A refusal with no field
     * goes on the line's facility.
     *
     * @return array<string, string>
     */
    public function lineErrors(int $line, FacilityBookingResult $result): array
    {
        $field = $this->field((string) $result->refusal);

        return ["bookings.{$line}.".($field === self::FORM ? 'facility_id' : $field) => $this->message($result)];
    }

    public function message(FacilityBookingResult $result): string
    {
        $name = $result->facilityName ?? __('The facility');

        return match ($result->refusal) {
            FacilityBookingResult::REFUSAL_FULL => __(':facility is fully booked at that time.', ['facility' => $name]),
            FacilityBookingResult::REFUSAL_INACTIVE => __('This facility is turned off. Turn it on in Settings, Facilities.'),
            FacilityBookingResult::REFUSAL_PRIVATE_NOT_OFFERED => __(':facility cannot be booked privately. Set a private price in Settings, Facilities.', ['facility' => $name]),
            FacilityBookingResult::REFUSAL_SESSION_NOT_FOUND => __('Choose one of the facility\'s sessions.'),
            FacilityBookingResult::REFUSAL_FOLIO_NOT_OPEN => __('The folio is closed. Reopen it on the Folio tab first.'),
            FacilityBookingResult::REFUSAL_OUTSIDE_EVENT => __('The date must be within the event\'s dates.'),
            FacilityBookingResult::REFUSAL_EVENT_NOT_OPEN => __('A completed or cancelled event takes no bookings.'),
            FacilityBookingResult::REFUSAL_EVENT_NOT_TENTATIVE => __('Only a tentative event can be confirmed.'),
            FacilityBookingResult::REFUSAL_BOOKING_CANCELLED => __('This booking is cancelled.'),
            FacilityBookingResult::REFUSAL_RESERVATION_NOT_LIVE => __('This reservation has no confirmed or in-house unit, so it takes no bookings.'),
            FacilityBookingResult::REFUSAL_FOLIO_NOT_OF_OWNER => __('Choose a folio of this reservation.'),
            FacilityBookingResult::REFUSAL_POSTED => __('This booking is already on the folio, so only its notes can change. Cancel it and book again to change it.'),
            default => __('The booking could not be saved.'),
        };
    }

    /**
     * The message for a Post now refusal, or for why the night audit could not post a booking.
     */
    public function postingMessage(string $code): string
    {
        return match ($code) {
            FacilityBookingResult::REFUSAL_BOOKING_CANCELLED => __('This booking is cancelled.'),
            FacilityBookingResult::REFUSAL_TENTATIVE => __('The booking is still Tentative. Confirm the event first.'),
            FacilityBookingResult::REFUSAL_CHARGED_BY_HAND => __('This facility has no price. Post the charge by hand on the folio.'),
            PostingResult::REFUSAL_FOLIO_NOT_OPEN => __('The folio is closed. Reopen it on the Folio tab first.'),
            PostingResult::REFUSAL_FOLIO_SETTLED => __('The folio was settled to the city ledger. Book again on another folio.'),
            PostingResult::REFUSAL_NO_BUSINESS_DATE => __('There is no open business date.'),
            'nationality_required' => __('The payer has no nationality, and a tax needs it.'),
            default => __('The charge could not be posted (:code).', ['code' => $code]),
        };
    }

    private function field(string $code): string
    {
        return match ($code) {
            FacilityBookingResult::REFUSAL_FULL, FacilityBookingResult::REFUSAL_SESSION_NOT_FOUND => 'starts_at',
            FacilityBookingResult::REFUSAL_INACTIVE => 'facility_id',
            FacilityBookingResult::REFUSAL_PRIVATE_NOT_OFFERED => 'private',
            FacilityBookingResult::REFUSAL_OUTSIDE_EVENT => 'booking_date',
            FacilityBookingResult::REFUSAL_FOLIO_NOT_OF_OWNER => 'charge_to',
            default => self::FORM,
        };
    }
}
