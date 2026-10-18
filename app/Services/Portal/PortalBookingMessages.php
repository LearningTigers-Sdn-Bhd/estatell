<?php

namespace App\Services\Portal;

use App\Actions\Portal\PortalBookingResult;
use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\ReservationResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * The words the company portal shows for a refusal, and the form field each one belongs to. A code that is not listed
 * gets the generic message and is logged, never shown. See docs/23-company-portal/01-company-portal.md#booking.
 */
class PortalBookingMessages
{
    public const string FORM = 'form';

    /**
     * Form errors keyed by field: `form`, or `lines.{index}.{field}` for a refusal that belongs to a line.
     *
     * @return array<string, string>
     */
    public function errors(PortalBookingResult $result): array
    {
        [$field, $message] = $this->entry((string) $result->refusal, $result->date, $result->violations);

        if ($result->lineIndex !== null && $field !== self::FORM) {
            return ["lines.{$result->lineIndex}.{$field}" => $message];
        }

        return [self::FORM => $message];
    }

    /**
     * The message alone, for a toast or a line error.
     *
     * @param  list<string>  $violations
     */
    public function message(string $code, ?CarbonInterface $date = null, array $violations = []): string
    {
        return $this->entry($code, $date, $violations)[1];
    }

    /**
     * @param  list<string>  $violations
     * @return array{0: string, 1: string}
     */
    private function entry(string $code, ?CarbonInterface $date, array $violations): array
    {
        $day = $date?->format('d M Y');

        return match ($code) {
            PortalBookingResult::REFUSAL_COMPANY_SUSPENDED => [self::FORM, __('Your company can\'t book at this property right now. Contact the property.')],
            ReservationResult::REFUSAL_NOT_AVAILABLE => ['rate_plan_id', $day === null ? __('Not available for these dates.') : __('Not available on :date.', ['date' => $day])],
            ReservationResult::REFUSAL_RESTRICTED => ['rate_plan_id', $violations === [] ? __('This stay breaks a rate restriction.') : implode(' ', $violations)],
            ReservationResult::REFUSAL_CHILD_AGE_INVALID => ['child_ages', __('A child age must be from 0 to 17.')],
            PortalBookingResult::REFUSAL_TOO_MANY_CHILDREN => ['child_ages', __('Too many children for this room type.')],
            PortalBookingResult::REFUSAL_TOO_MANY_ADULTS => ['adults', __('Too many adults for this room type.')],
            BookingResult::REFUSAL_GUEST_NAME_REQUIRED => ['guest_name', __('Enter a guest name for each room.')],
            ReservationResult::REFUSAL_CREDIT_LIMIT_EXCEEDED => [self::FORM, __('This booking would take the company over its credit limit. Contact the property.')],
            PortalBookingResult::REFUSAL_PLAN_NOT_VISIBLE => [self::FORM, __('This rate is not available. Search again.')],
            PortalBookingResult::REFUSAL_PRICE_CHANGED => [self::FORM, __('The price changed. Search again.')],
            PortalBookingResult::REFUSAL_NOT_EDITABLE => [self::FORM, __('The guests can no longer change. Contact the property.')],
            PortalBookingResult::REFUSAL_GUEST_NOT_FOUND => [self::FORM, __('The guests of this booking changed. Reload the page and try again.')],
            PortalBookingResult::REFUSAL_PAST_CUT_OFF => [self::FORM, __('The free cancellation period has ended. Ask the property to cancel.')],
            PortalBookingResult::REFUSAL_NOT_CANCELLABLE => [self::FORM, __('This booking cannot be cancelled or have a cancellation requested now.')],
            PortalBookingResult::REFUSAL_ALREADY_REQUESTED => [self::FORM, __('You already asked the property to cancel this booking.')],
            default => $this->generic($code),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function generic(string $code): array
    {
        Log::warning('Company portal booking refused.', ['code' => $code]);

        return [self::FORM, __('This booking cannot be made. Contact the property.')];
    }
}
