<?php

namespace App\Actions\BookingEngine;

use App\Actions\Payments\RecordPayment;
use App\Actions\Reservations\CreateBooking;
use App\Data\Payments\NewPaymentData;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Enums\BillingMode;
use App\Enums\BookingHoldStatus;
use App\Enums\BookingSourceType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Models\BookingHold;
use App\Models\BookingHoldLine;
use App\Models\BookingSource;
use App\Models\GatewayPayment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\Reservations\AutoAddOns;
use Illuminate\Support\Facades\DB;

/**
 * Turn a Paid hold into a booking, in one transaction, once per order. The hold is marked Confirmed first, because a Paid
 * hold counts in availability and `AddUnit` would otherwise refuse its own rooms. Other requests still see it as Paid until
 * the commit. Then a new guest and the booking, on the Booking Engine source with no staff actor, so every rule still
 * applies. Each unit has its own folio, and takes its line's share of the payment as an Advance with the key
 * `online:{order}:{unit}`. A refusal rolls it all back, and the hold stays Paid. See docs/29-booking-engine/04-payment.md#confirmation.
 */
class ConfirmBookingHold
{
    /** A staff booking locks its room types in line order, so this can deadlock with it. */
    private const int ATTEMPTS = 3;

    public function __construct(
        private CreateBooking $createBooking,
        private RecordPayment $recordPayment,
        private AutoAddOns $autoAddOns,
    ) {}

    public function __invoke(BookingHold $hold, GatewayPayment $payment): ConfirmBookingHoldResult
    {
        try {
            return DB::transaction(fn (): ConfirmBookingHoldResult => $this->confirm($hold, $payment), self::ATTEMPTS);
        } catch (BookingHoldNotConfirmed $refused) {
            return ConfirmBookingHoldResult::refused($refused->refusal);
        }
    }

    /**
     * @throws BookingHoldNotConfirmed
     */
    private function confirm(BookingHold $hold, GatewayPayment $payment): ConfirmBookingHoldResult
    {
        RoomType::query()
            ->where('property_id', $hold->property_id)
            ->whereIn('id', $hold->lines()->reorder()->select('room_type_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        $locked = BookingHold::query()->with(['property', 'lines.children'])->lockForUpdate()->findOrFail($hold->id);

        if ($locked->status === BookingHoldStatus::Confirmed && $locked->reservation_id !== null) {
            return ConfirmBookingHoldResult::confirmed(Reservation::query()->findOrFail($locked->reservation_id));
        }

        if ($locked->status !== BookingHoldStatus::Paid) {
            return ConfirmBookingHoldResult::refused(ConfirmBookingHoldResult::REFUSAL_NOT_PAID);
        }

        $source = BookingSource::query()->where('property_id', $locked->property_id)->where('type', BookingSourceType::BookingEngine)->where('is_system', true)->first();
        $method = PaymentMethod::query()->where('property_id', $locked->property_id)->where('type', PaymentMethodType::Online)->first();

        if ($source === null) {
            return ConfirmBookingHoldResult::refused(ConfirmBookingHoldResult::REFUSAL_NO_SOURCE);
        }

        if ($method === null) {
            return ConfirmBookingHoldResult::refused(ConfirmBookingHoldResult::REFUSAL_NO_METHOD);
        }

        $locked->update(['status' => BookingHoldStatus::Confirmed]);

        $booking = ($this->createBooking)(null, $locked->property, $this->bookingData($locked, $source));

        if ($booking->isRefused()) {
            throw new BookingHoldNotConfirmed((string) $booking->refusal);
        }

        $reservation = $booking->reservation;
        $this->postPayments($locked, $payment, $reservation, $method);
        $locked->update(['reservation_id' => $reservation->id]);

        return ConfirmBookingHoldResult::confirmed($reservation);
    }

    private function bookingData(BookingHold $hold, BookingSource $source): NewBookingData
    {
        $addOns = $this->autoAddOns->forUnit($hold->property);

        return new NewBookingData(
            bookingSource: $source,
            units: array_values($hold->lines->map(fn (BookingHoldLine $line): NewUnitData => new NewUnitData(
                roomTypeId: $line->room_type_id,
                ratePlanId: $line->rate_plan_id,
                arrivalDate: $hold->arrival_date,
                departureDate: $hold->departure_date,
                adults: $line->adults,
                childAges: array_values($line->children->pluck('age')->all()),
                addOns: $addOns,
            ))->all()),
            guests: [new NewBookingGuestData(
                profile: [
                    'full_name' => (string) $hold->guest_name,
                    'email' => $hold->guest_email,
                    'phone' => $hold->guest_phone,
                    'nationality_code' => $hold->guest_nationality,
                ],
                unitIndex: 0,
            )],
            billingMode: BillingMode::EachPays,
            notes: $hold->special_requests,
        );
    }

    /**
     * One `payments` row for each unit, each for its line's share. Units and lines are in the same order.
     *
     * @throws BookingHoldNotConfirmed
     */
    private function postPayments(BookingHold $hold, GatewayPayment $payment, Reservation $reservation, PaymentMethod $method): void
    {
        $units = $reservation->units()->orderBy('id')->get()->values();

        foreach ($hold->lines->values() as $index => $line) {
            $unit = $units->get($index);

            if ($unit === null) {
                throw new BookingHoldNotConfirmed('unit_not_found');
            }

            $key = "online:{$payment->order_id}:{$unit->id}";
            $result = ($this->recordPayment)(null, $reservation, null, $unit, new NewPaymentData(
                paymentMethodId: $method->id,
                purpose: PaymentPurpose::Advance,
                amount: $line->amount,
                reference: $payment->gateway_payment_id,
                idempotencyKey: $key,
                lineKey: $key,
                allowOnline: true,
            ));

            if ($result->isRefused()) {
                throw new BookingHoldNotConfirmed((string) $result->refusal);
            }
        }
    }
}
