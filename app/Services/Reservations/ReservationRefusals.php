<?php

namespace App\Services\Reservations;

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
     * The message alone, for a toast.
     */
    public function message(string $code, ?CarbonInterface $date = null): string
    {
        return $this->entry($code, $date, false)[1];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function entry(string $code, ?CarbonInterface $date, bool $canOverrideRestrictions): array
    {
        $day = $date?->format('d M Y') ?? '';

        return match ($code) {
            ReservationResult::REFUSAL_RESERVATION_CLOSED => [self::FORM, __('This reservation is closed, so it cannot change.')],
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
