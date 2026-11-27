<?php

namespace App\Services\Facilities;

use App\Actions\Facilities\FacilityBookingResult;
use App\Data\Facilities\FacilityBookingInputData;
use App\Data\Facilities\FacilityBookingQuoteData;
use App\Data\Facilities\FacilityQuoteTaxData;
use App\Data\Facilities\FacilitySessionPlacesData;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\FacilitySession;
use App\Models\Reservation;
use App\Services\Catalogues\CalculatedTax;
use App\Services\Catalogues\TaxCalculator;
use Brick\Money\Money;

/**
 * Quotes a facility booking for the Book facility Sheet: the price with its taxes (with the payer's nationality), the
 * refusal `BookFacility` or `UpdateFacilityBooking` would give, and the Tentative holds of others as a warning. The
 * browser shows these and calculates nothing. See docs/33-facilities/04-facility-bookings.md#price.
 */
class FacilityBookingQuote
{
    public function __construct(
        private FacilityAvailability $availability,
        private FacilityBookingChecks $checks,
        private FacilityBookingRefusals $refusals,
        private TaxCalculator $taxCalculator,
        private ReservationChargeTo $chargeTo,
    ) {}

    /**
     * `$data` may have empty times while staff have not picked them; then only the sessions are given. `$event` may be
     * unsaved (a New event page line), with `$pending` its earlier lines.
     *
     * @param  iterable<FacilityBooking>  $pending
     */
    public function forEvent(Event $event, Facility $facility, FacilityBookingInputData $data, ?FacilityBooking $booking = null, iterable $pending = []): FacilityBookingQuoteData
    {
        $event->loadMissing('payerGuest');

        return $this->build(
            $facility,
            $data,
            $booking,
            fn (): ?string => $this->checks->forEvent($event, $facility, $data, $booking?->id, $pending),
            $event->id,
            $event->payerGuest?->nationality_code,
            $pending,
        );
    }

    /**
     * A reservation's booking. Taxes use the nationality of the payer of the Charge to folio, else of the booker.
     * Charge to itself is checked when saving.
     */
    public function forReservation(Reservation $reservation, Facility $facility, FacilityBookingInputData $data, ?FacilityBooking $booking = null): FacilityBookingQuoteData
    {
        $folio = $this->chargeTo->resolve($reservation, $data->chargeTo)['folio'] ?? null;
        $folio?->loadMissing('payerGuest');
        $reservation->loadMissing('booker');

        return $this->build(
            $facility,
            $data,
            $booking,
            fn (): ?string => $this->checks->forReservation($reservation, $facility, $data, $booking?->id),
            null,
            $folio->payerGuest->nationality_code ?? $reservation->booker?->nationality_code,
        );
    }

    /**
     * @param  callable(): ?string  $refusalFor
     * @param  iterable<FacilityBooking>  $pending
     */
    private function build(Facility $facility, FacilityBookingInputData $data, ?FacilityBooking $booking, callable $refusalFor, ?int $exceptEventId, ?string $nationality, iterable $pending = []): FacilityBookingQuoteData
    {
        $facility->loadMissing(['chargeItem.transactionCode', 'sessions' => fn ($query) => $query->ordered()]);
        $sessions = array_values($facility->sessions
            ->map(fn (FacilitySession $session): FacilitySessionPlacesData => new FacilitySessionPlacesData(
                substr($session->starts_at, 0, 5),
                substr($session->ends_at, 0, 5),
                $this->availability->placesLeft($facility, $data->bookingDate, $session->starts_at, $session->ends_at, $booking?->id, $pending),
            ))
            ->all());

        if ($data->startsAt === '' || $data->endsAt === '' || $data->endsAt <= $data->startsAt) {
            return new FacilityBookingQuoteData(null, [], null, null, null, $sessions);
        }

        $refusal = $refusalFor();
        $holds = $this->availability->tentativeHolds($facility, $data->bookingDate, $data->startsAt, $data->endsAt, $booking?->id, $exceptEventId);
        $warning = $holds->isEmpty() ? null : __(':facility is held :from–:to (:owner, Tentative).', [
            'facility' => $facility->name,
            'from' => substr($holds->first()->starts_at, 0, 5),
            'to' => substr($holds->first()->ends_at, 0, 5),
            'owner' => $holds->first()->event->name ?? __('a reservation'),
        ]);
        $refusalMessage = $refusal === null ? null : $this->refusals->message(FacilityBookingResult::refused($refusal, $facility->name));

        $listPrice = $data->private ? $facility->private_amount : $facility->chargeItem->default_amount;
        $keptAmount = $booking !== null && $booking->private === $data->private ? $booking->unit_amount : null;
        $unitAmount = $listPrice === null ? null : ($data->unitAmount ?? $keptAmount ?? (string) $listPrice);

        if ($unitAmount === null || preg_match('/^\d+(\.\d{1,2})?$/', $unitAmount) !== 1) {
            return new FacilityBookingQuoteData(null, [], null, $refusalMessage, $warning, $sessions);
        }

        $property = $facility->property()->with('settings')->firstOrFail();
        $units = $facility->rate_basis->quantity($data->startsAt, $data->endsAt, $data->quantity, $facility->booked_by, $data->private);
        $breakdown = $facility->rate_basis->breakdown($data->startsAt, $data->endsAt, $data->quantity, $facility->booked_by, $data->private);
        $amount = Money::of($unitAmount, $property->settings->currency->getCurrencyCode())->multipliedBy($units);
        $tax = $this->taxCalculator->calculate($property, $facility->chargeItem->transactionCode, $amount, (string) $units, $nationality);

        if (! $tax->isCalculated()) {
            return new FacilityBookingQuoteData((string) $amount->getAmount(), [], null, $refusalMessage, $warning, $sessions, $breakdown);
        }

        return new FacilityBookingQuoteData(
            (string) $amount->getAmount(),
            array_map(fn (CalculatedTax $one): FacilityQuoteTaxData => new FacilityQuoteTaxData($one->name, (string) $one->amount->getAmount(), $one->inclusive), $tax->taxes),
            (string) $tax->grossAmount?->getAmount(),
            $refusalMessage,
            $warning,
            $sessions,
            $breakdown,
        );
    }
}
