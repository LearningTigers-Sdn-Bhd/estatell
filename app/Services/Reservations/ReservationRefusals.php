<?php

namespace App\Services\Reservations;

use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Reservations\AddOnResult;
use App\Actions\Reservations\AssignmentResult;
use App\Actions\Reservations\AttachGuestResult;
use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\ReservationResult;
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
     * The errors to flash back to a form, keyed by field name.
     *
     * @return array<string, string>
     */
    public function errors(string $code, ?CarbonInterface $date = null, bool $canOverrideRestrictions = false): array
    {
        [$field, $message] = $this->entry($code, $date, $canOverrideRestrictions);

        return [$field => $message];
    }

    /**
     * The errors to flash back to a unit action form (cancel, no-show, reinstate, change dates), keyed by field name.
     * A code the form does not know falls back to the shared list.
     *
     * @return array<string, string>
     */
    public function unitErrors(string $code, ?CarbonInterface $date = null, bool $canOverrideRestrictions = false, bool $canOverrideCard = false): array
    {
        $entry = $this->unitEntry($code, $canOverrideRestrictions, $canOverrideCard) ?? $this->entry($code, $date, $canOverrideRestrictions);

        return [$entry[0] => $entry[1]];
    }

    /**
     * The message alone, for a toast or a preview.
     */
    public function message(string $code, ?CarbonInterface $date = null): string
    {
        return ($this->unitEntry($code, false) ?? $this->entry($code, $date, false))[1];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function unitEntry(string $code, bool $canOverrideRestrictions, bool $canOverrideCard = false): ?array
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
            UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED => [self::FORM, __('The unit folio is not settled. Collect the balance or refund the deposit first.')],
            UnitStayResult::REFUSAL_NOT_DEPARTURE_DATE => [self::FORM, __('A late check-out is only for the departure date.')],
            UnitStayResult::REFUSAL_TIME_NOT_LATER => ['new_time', __('Choose a time later than the current departure time.')],
            'refund_exceeds_payment' => ['refund_amount', __('The refund is more than the deposit held.')],
            'method_not_available' => ['payment_method_id', __('This payment method is not available.')],
            'reference_required' => ['reference', __('This payment method needs a reference.')],
            'folio_not_open' => [self::FORM, __('The unit folio is closed, so it cannot change.')],
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
            UnitStayResult::REFUSAL_RESTRICTED => $canOverrideRestrictions
                ? ['restriction_override_reason', __('The new nights break a rate restriction. Enter a reason to change the dates anyway.')]
                : [self::FORM, __('The new nights break a rate restriction, for example a minimum stay. Ask someone who may book past restrictions.')],
            default => null,
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function entry(string $code, ?CarbonInterface $date, bool $canOverrideRestrictions): array
    {
        $day = $date?->format('d M Y') ?? '';

        return match ($code) {
            ReservationResult::REFUSAL_RESERVATION_CLOSED => [self::FORM, __('This reservation is closed, so it cannot change.')],
            ReservationResult::REFUSAL_COMPANY_NOT_AVAILABLE => ['company_id', __('This company is not available. Pick an active company.')],
            ReservationResult::REFUSAL_RATE_PLAN_NOT_LINKED_TO_COMPANY => ['rate_plan_id', __('This rate plan is for certain companies only. Pick one of them on the reservation first.')],
            ReservationResult::REFUSAL_COMPANY_PLAN_CONFLICT => ['company_id', __('Units use plans only the old company can book.')],
            ReservationResult::REFUSAL_CREDIT_LIMIT_EXCEEDED => ['credit_override_reason', __('This company is over its credit limit.')],
            ReservationResult::REFUSAL_MASTER_HAS_LINES => ['company_id', __('Move or void the master folio\'s charges first.')],
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
            RateQuote::REFUSAL_PLAN_ARCHIVED => ['rate_plan_id', __('This rate plan is archived.')],
            RateQuote::REFUSAL_ROOM_TYPE_NOT_SOLD => ['room_type_id', __('This room type is not sold on this rate plan.')],
            RateQuote::REFUSAL_STAY_LENGTH_MISMATCH => ['departure_date', __('This package sells a fixed number of nights.')],
            RateQuote::REFUSAL_RATE_MISSING => [self::FORM, $day === '' ? __('No rate is set for these dates.') : __('No rate is set for :date.', ['date' => $day])],
            RateQuote::REFUSAL_PACKAGE_PRICE_MISSING => [self::FORM, __('The package has no price for these dates.')],
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
