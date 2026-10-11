<?php

namespace App\Services\Reservations;

use App\Actions\Folio\PostingResult;
use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Reservations\AddOnResult;
use App\Actions\Reservations\AssignmentResult;
use App\Actions\Reservations\AttachGuestResult;
use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\GroupUpdateResult;
use App\Actions\Reservations\NightPriceResult;
use App\Actions\Reservations\ReservationResult;
use App\Services\Folio\IncomingScheduledCharges;
use App\Services\Packages\InclusionSplit;
use App\Services\Rates\RateQuote;
use Carbon\CarbonInterface;

/**
 * Turns the refusal codes of the reservation Actions into a message and the form field it belongs to. The reservation
 * page and the booking form share it. A refusal with no matching field uses `form`, a message for the whole form.
 */
class ReservationRefusals
{
    public const string FORM = 'form';

    /**
     * The errors to flash back to a form, keyed by field name. `$detail` is the amount held (`master_has_payments`, as
     * `MYR 1,000.00`) or the invoice number (`master_has_ar_invoice`).
     *
     * @return array<string, string>
     */
    public function errors(string $code, ?CarbonInterface $date = null, bool $canOverrideRestrictions = false, ?string $detail = null): array
    {
        [$field, $message] = $this->entry($code, $date, $canOverrideRestrictions, $detail);

        return [$field => $message];
    }

    /**
     * The errors to flash back to the Edit group form. `$amount` is the money the Master folio holds, as `MYR 1,000.00`.
     *
     * @return array<string, string>
     */
    public function groupErrors(string $code, ?string $amount = null): array
    {
        return match ($code) {
            GroupUpdateResult::REFUSAL_MASTER_HAS_PAYMENTS => ['billing_mode' => __('The Master folio holds :amount in payments. Refund it before changing the billing mode.', ['amount' => $amount])],
            default => [self::FORM => __('This change was refused.')],
        };
    }

    /**
     * The errors to flash back to a unit action form (cancel, no-show, reinstate, change dates), keyed by field name.
     * A code the form does not know falls back to the shared list.
     *
     * @param  list<string>  $rooms  `UnitStayResult::$rooms`: the rooms named by `balance_not_settled` and
     *                               `folio_receives_scheduled_charges`.
     * @return array<string, string>
     */
    public function unitErrors(string $code, ?CarbonInterface $date = null, bool $canOverrideRestrictions = false, bool $canOverrideCard = false, array $rooms = []): array
    {
        $entry = $this->unitEntry($code, $canOverrideRestrictions, $canOverrideCard, $rooms) ?? $this->entry($code, $date, $canOverrideRestrictions);

        return [$entry[0] => $entry[1]];
    }

    /**
     * The errors of the Change price Dialog: a refusal of a field it does not have shows at the top of the form.
     *
     * @return array<string, string>
     */
    public function nightPriceErrors(string $code): array
    {
        $errors = $this->unitErrors($code);
        $field = (string) array_key_first($errors);

        return in_array($field, ['night_ids', 'price', 'reason'], true) ? $errors : [self::FORM => $errors[$field]];
    }

    /**
     * The message alone, for a toast or a preview.
     */
    public function message(string $code, ?CarbonInterface $date = null): string
    {
        return ($this->unitEntry($code, false) ?? $this->entry($code, $date, false))[1];
    }

    /**
     * @param  list<string>  $rooms
     * @return array{0: string, 1: string}|null
     */
    private function unitEntry(string $code, bool $canOverrideRestrictions, bool $canOverrideCard = false, array $rooms = []): ?array
    {
        return match ($code) {
            UnitStayResult::REFUSAL_INVALID_STATUS => [self::FORM, __('This unit cannot do that in its current status.')],
            UnitStayResult::REFUSAL_NO_BUSINESS_DATE => [self::FORM, __('This property has no open business date yet.')],
            UnitStayResult::REFUSAL_STAY_ENDED => [self::FORM, __('The stay has ended.')],
            UnitStayResult::REFUSAL_ARRIVAL_NOT_REACHED => [self::FORM, __('The guest cannot check in before the arrival date.')],
            UnitStayResult::REFUSAL_NO_ROOM_ASSIGNED => [self::FORM, __('Assign a room or bed for tonight before checking in.')],
            UnitStayResult::REFUSAL_CARD_MISSING => [self::FORM, $canOverrideCard
                ? __('A registration card is not signed. Sign it, or tick Check in without a card.')
                : __('A registration card is not signed. Sign it first, or ask someone who may check in without a card.')],
            UnitStayResult::REFUSAL_NATIONALITY_REQUIRED => ['nationality_code', __('A tax depends on nationality. Choose the guest\'s nationality.')],
            UnitStayResult::REFUSAL_CREDIT_LIMIT_EXCEEDED => ['credit_override_reason', __('This company is over its credit limit.')],
            UnitStayResult::REFUSAL_BEFORE_DEPARTURE_DATE => [self::FORM, __('The guest cannot check out before the departure date. Change the dates first.')],
            UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED => [self::FORM, $rooms === []
                ? __('The unit folio is not settled. Collect the balance or refund the deposit first.')
                : __('The unit folio is not settled. It holds :amounts. Collect it, or move those charges first.', ['amounts' => implode(', ', $rooms)])],
            UnitStayResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES => [self::FORM, IncomingScheduledCharges::message($rooms)],
            UnitStayResult::REFUSAL_NOT_EARLY => [self::FORM, __('The departure date is today or past, so check out the normal way.')],
            UnitStayResult::REFUSAL_NOT_DEPARTURE_DATE => [self::FORM, __('A late check-out is only for the departure date.')],
            UnitStayResult::REFUSAL_TIME_NOT_LATER => ['new_time', __('Choose a time later than the current departure time.')],
            'refund_exceeds_payment' => ['refund_amount', __('The refund is more than the deposit held.')],
            'method_not_available' => ['payment_method_id', __('This payment method is not available.')],
            'reference_required' => ['reference', __('This payment method needs a reference.')],
            'folio_not_open' => [self::FORM, __('The unit folio is closed, so it cannot change.')],
            PostingResult::REFUSAL_FOLIO_SETTLED => [self::FORM, __('A folio of these nights is settled to the city ledger, so it cannot change. Issue a credit note on its AR invoice instead.')],
            NightPriceResult::REFUSAL_NIGHTS_INVALID => ['night_ids', __('Tick at least one night of this unit.')],
            NightPriceResult::REFUSAL_PRICE_INVALID => ['price', __('Enter a price of 0 or more, with up to two decimals.')],
            InclusionSplit::REFUSAL_EXCEEDS_NIGHT_AMOUNT => ['price', __('The price is less than the inclusions of the night.')],
            UnitStayResult::REFUSAL_REASON_REQUIRED => ['reason', __('Enter a reason.')],
            UnitStayResult::REFUSAL_FEE_CHANGED => ['expected_fee', __('The fee changed while this form was open. Check the new amount and confirm again.')],
            UnitStayResult::REFUSAL_POLICY_NOT_FOUND => [self::FORM, __('The policy for this fee is missing. Check Room revenue in settings.')],
            UnitStayResult::REFUSAL_NOT_AVAILABLE => [self::FORM, __('No unit of this room type is free for these dates.')],
            UnitStayResult::REFUSAL_INVALID_STAY => ['departure_date', __('Departure must be after arrival.')],
            UnitStayResult::REFUSAL_DATES_UNCHANGED => ['departure_date', __('The dates are the same as now.')],
            UnitStayResult::REFUSAL_ARRIVAL_LOCKED => ['arrival_date', __('Arrival cannot change once the guest is in the house.')],
            UnitStayResult::REFUSAL_ARRIVAL_IN_PAST => ['arrival_date', __('Arrival cannot be before the business date.')],
            UnitStayResult::REFUSAL_DEPARTURE_IN_PAST => ['departure_date', __('Departure cannot be before the business date.')],
            UnitStayResult::REFUSAL_PACKAGE_PLAN => [self::FORM, __('A package plan sells a fixed number of nights, so its dates cannot change here.')],
            UnitStayResult::REFUSAL_DAY_USE_UNIT => [self::FORM, __('A day use has one date and fixed hours, so its dates cannot change.')],
            UnitStayResult::REFUSAL_RESTRICTED => $canOverrideRestrictions
                ? ['restriction_override_reason', __('The new nights break a rate restriction. Enter a reason to change the dates anyway.')]
                : [self::FORM, __('The new nights break a rate restriction, for example a minimum stay. Ask someone who may book past restrictions.')],
            default => null,
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function entry(string $code, ?CarbonInterface $date, bool $canOverrideRestrictions, ?string $detail = null): array
    {
        $day = $date?->format('d M Y') ?? '';

        return match ($code) {
            ReservationResult::REFUSAL_RESERVATION_CLOSED => [self::FORM, __('This reservation is closed, so it cannot change.')],
            ReservationResult::REFUSAL_COMPANY_NOT_AVAILABLE => ['company_id', __('This company is not available. Pick an active company.')],
            ReservationResult::REFUSAL_RATE_PLAN_NOT_LINKED_TO_COMPANY => ['rate_plan_id', __('This rate plan is for certain companies only. Pick one of them on the reservation first.')],
            ReservationResult::REFUSAL_COMPANY_PLAN_CONFLICT => ['company_id', __('Units use plans only the old company can book.')],
            ReservationResult::REFUSAL_CREDIT_LIMIT_EXCEEDED => ['credit_override_reason', __('This company is over its credit limit.')],
            ReservationResult::REFUSAL_MASTER_HAS_PAYMENTS => ['company_id', __('Refund the :amount payment on the Master folio first, then change the company.', ['amount' => $detail])],
            ReservationResult::REFUSAL_MASTER_HAS_AR_INVOICE => ['company_id', __('Void invoice :number first, then change the company.', ['number' => $detail])],
            ReservationResult::REFUSAL_UNITS_REQUIRED => [self::FORM, __('Add at least one room or bed.')],
            ReservationResult::REFUSAL_BOOKER_NOT_FOUND => ['guest_id', __('The booker is not one of this property\'s guests.')],
            ReservationResult::REFUSAL_ROOM_TYPE_NOT_FOUND => ['room_type_id', __('This room type is not for sale.')],
            ReservationResult::REFUSAL_RATE_PLAN_NOT_LINKED => ['rate_plan_id', __('This rate plan is not sold for the room type.')],
            ReservationResult::REFUSAL_INVALID_STAY => ['departure_date', __('Departure must be after arrival.')],
            ReservationResult::REFUSAL_ARRIVAL_IN_PAST => ['arrival_date', __('Arrival cannot be before today.')],
            ReservationResult::REFUSAL_ADULTS_REQUIRED => ['adults', __('At least one adult is required.')],
            ReservationResult::REFUSAL_CHILD_AGE_INVALID => ['child_ages', __('A child age must be from 0 to 17.')],
            ReservationResult::REFUSAL_DISCOUNT_NOT_ALLOWED => [self::FORM, __('This discount cannot be used on rooms.')],
            ReservationResult::REFUSAL_RESTRICTED => $canOverrideRestrictions
                ? ['restriction_override_reason', __('This stay breaks a rate restriction. Enter a reason to book it anyway.')]
                : [self::FORM, __('This stay breaks a rate restriction, for example a minimum stay. Ask someone who may book past restrictions.')],
            ReservationResult::REFUSAL_RESTRICTION_OVERRIDE_NOT_ALLOWED => [self::FORM, __('You may not book past rate restrictions.')],
            ReservationResult::REFUSAL_NOT_AVAILABLE => ['room_type_id', $day === '' ? __('No unit is available for these dates.') : __('No unit is available on :date.', ['date' => $day])],
            ReservationResult::REFUSAL_OVERRIDE_REASON_REQUIRED => ['override_reason', __('Enter a reason for the price override.')],
            ReservationResult::REFUSAL_OVERRIDE_INVALID => ['override_total', __('Enter an amount such as 840.00.')],
            ReservationResult::REFUSAL_OVERRIDE_NOT_ALLOWED => ['override_total', __('The price of a day use cannot be overridden.')],
            ReservationResult::REFUSAL_STAY_TYPE_MISMATCH => [self::FORM, __('This reservation is all overnight or all day use. Start a new booking for the other kind.')],
            ReservationResult::REFUSAL_STAY_TYPES_MIXED => [self::FORM, __('A booking is all overnight or all day use. Book the other kind separately.')],
            ReservationResult::REFUSAL_DAY_USE_NOT_FOR_CHANNEL => [self::FORM, __('Day use is sold at the front desk only.')],
            ReservationResult::REFUSAL_OUTSIDE_SOLD_BETWEEN => ['start_time', __('The block must start and end inside the hours this plan is sold.')],
            ReservationResult::REFUSAL_START_TIME_INVALID => ['start_time', __('Start on the hour or the half hour, and end on the same day.')],
            ReservationResult::REFUSAL_ROOM_REQUIRED => ['room_id', __('Choose a room. Day use is sold by room.')],
            RateQuote::REFUSAL_PLAN_NOT_DAY_USE => ['rate_plan_id', __('Pick a day use plan for a day use booking.')],
            RateQuote::REFUSAL_NO_HOUR_PRICE => ['hours', __('No price is set for this number of hours on this date.')],
            RateQuote::REFUSAL_HOURS_NOT_SOLD => ['hours', __('This plan does not sell that number of hours.')],
            AddOnResult::REFUSAL_NIGHTLY_ITEM_FOR_DAY_USE => ['charge_item_id', __('A nightly item cannot be added to a day use unit.')],
            AssignmentResult::REFUSAL_DAY_USE_UNIT => [self::FORM, __('The room of a day use unit is chosen at booking.')],
            RateQuote::REFUSAL_PLAN_NOT_NIGHTLY => ['rate_plan_id', __('This is a day use plan. It cannot be booked for nights.')],
            RateQuote::REFUSAL_PLAN_ARCHIVED => ['rate_plan_id', __('This rate plan is archived.')],
            RateQuote::REFUSAL_ROOM_TYPE_NOT_SOLD => ['room_type_id', __('This room type is not sold on this rate plan.')],
            RateQuote::REFUSAL_STAY_LENGTH_MISMATCH => ['departure_date', __('This package sells a fixed number of nights.')],
            RateQuote::REFUSAL_RATE_MISSING => [self::FORM, $day === '' ? __('No rate is set for these dates.') : __('No rate is set for :date.', ['date' => $day])],
            RateQuote::REFUSAL_PACKAGE_PRICE_MISSING => [self::FORM, __('The package has no price for these dates.')],
            RateQuote::REFUSAL_PACKAGE_BELOW_INCLUSIONS => [self::FORM, __('The package price is less than its inclusions.')],
            RateQuote::REFUSAL_RATE_BELOW_INCLUSIONS => [self::FORM, $day === '' ? __('The rate is less than its inclusions.') : __('The rate on :date is less than its inclusions.', ['date' => $day])],
            RateQuote::REFUSAL_CHILD_PRICE_MISSING => ['child_ages', __('No child price is set for these ages.')],
            RateQuote::REFUSAL_BEDS_REQUIRED => [self::FORM, __('At least one bed is required.')],
            AttachGuestResult::REFUSAL_GUEST_NOT_FOUND => ['guest_id', __('This guest cannot be found.')],
            AttachGuestResult::REFUSAL_ALREADY_ATTACHED => ['guest_id', __('This guest already stays in this unit.')],
            AttachGuestResult::REFUSAL_BED_UNIT_FULL => [self::FORM, __('A bed unit holds one guest.')],
            AddOnResult::REFUSAL_UNIT_NOT_OPEN => [self::FORM, __('This unit is closed, so it cannot change.')],
            AddOnResult::REFUSAL_CHARGE_ITEM_NOT_FOUND => ['charge_item_id', __('This charge item is not available.')],
            AddOnResult::REFUSAL_SERVICE_DATE_OUTSIDE_STAY => ['service_date', __('The service date must be within the stay.')],
            AddOnResult::REFUSAL_SERVICE_DATE_IN_PAST => ['service_date', __('The service date cannot be before today.')],
            AddOnResult::REFUSAL_QUANTITY_INVALID => ['quantity', __('Enter a quantity from 1 to 999.')],
            AddOnResult::REFUSAL_AMOUNT_INVALID => ['unit_amount', __('Enter an amount such as 25.00.')],
            AddOnResult::REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED => ['unit_amount', __('This item does not allow a different amount.')],
            AssignmentResult::REFUSAL_REASON_REQUIRED => ['reason', __('Enter a reason.')],
            AssignmentResult::REFUSAL_NIGHT_INVALID => ['from_night', __('Choose a night inside the stay.')],
            AssignmentResult::REFUSAL_NIGHT_IN_PAST => ['from_night', __('A move cannot start before the business date.')],
            AssignmentResult::REFUSAL_ROOM_TYPE_NOT_ALLOWED => [self::FORM, __('This room type is not sold on the unit\'s rate plan.')],
            AssignmentResult::REFUSAL_WHOLE_STAY_REQUIRED => ['from_night', __('A move to another room type must start on the arrival night.')],
            AssignmentResult::REFUSAL_UNCHANGED => [self::FORM, __('The unit is already there from this night.')],
            AssignmentResult::REFUSAL_ROOM_NOT_FOUND => ['room_id', __('This room cannot be picked.')],
            AssignmentResult::REFUSAL_BED_NOT_FOUND => ['bed_id', __('This bed cannot be picked.')],
            AssignmentResult::REFUSAL_ROOM_NOT_FREE => ['room_id', $day === '' ? __('This room is not free for the stay.') : __('This room is not free on :date.', ['date' => $day])],
            AssignmentResult::REFUSAL_BED_NOT_FREE => ['bed_id', $day === '' ? __('This bed is not free for the stay.') : __('This bed is not free on :date.', ['date' => $day])],
            BookingResult::REFUSAL_BOOKER_REQUIRED => ['guest_id', __('Choose the booker.')],
            BookingResult::REFUSAL_GUEST_NAME_REQUIRED => ['full_name', __('Enter the guest\'s name or pick an existing guest.')],
            BookingResult::REFUSAL_UNIT_NOT_FOUND => [self::FORM, __('This unit is not on the booking.')],
            default => [self::FORM, __('This change was refused.')],
        };
    }
}
