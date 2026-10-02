<?php

namespace App\Services\Reservations;

use App\Actions\Reservations\AddOnResult;
use App\Actions\Reservations\AttachGuestResult;
use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\ReservationResult;

/**
 * Turns the refusal of `CreateBooking` into form errors keyed by position, for the booking form: `lines.1.room_type_id`,
 * `lines.0.bed_ids.2`, `arrival_date`, `guests.0.guest_id` or `form`. The field and message come from
 * `ReservationRefusals`; the line comes from the line, or from the unit index of the result.
 */
class BookingErrorKeys
{
    /** The fields that belong to a line, and are keyed by it. */
    private const array LINE_FIELDS = ['room_type_id', 'rate_plan_id', 'adults', 'child_ages', 'discount_id', 'override_total', 'override_reason', 'restriction_override_reason'];

    /** The add-on fields: a refusal inside a line does not say which add-on, so they key the line's add-ons. */
    private const array ADD_ON_FIELDS = ['charge_item_id', 'quantity', 'unit_amount', 'service_date'];

    public function __construct(private ReservationRefusals $refusals) {}

    /**
     * @param  list<int>  $unitsPerLine  How many units each line adds, in line order.
     * @return array<string, string>
     */
    public function errors(BookingResult $result, array $unitsPerLine, bool $canOverrideRestrictions): array
    {
        $errors = $this->refusals->errors((string) $result->refusal, $result->date, $canOverrideRestrictions);
        $field = (string) array_key_first($errors);
        $message = $errors[$field];

        if ($field === 'arrival_date' || $field === 'departure_date') {
            return [$field => $message];
        }

        if ($result->guestIndex !== null || in_array($result->refusal, [ReservationResult::REFUSAL_BOOKER_NOT_FOUND, BookingResult::REFUSAL_BOOKER_REQUIRED], true)) {
            return ['guests.'.($result->guestIndex ?? 0).'.'.$this->guestField($result->refusal, $field) => $message];
        }

        if ($field === ReservationRefusals::FORM) {
            return [$field => $message];
        }

        if ($result->unitIndex !== null && ($field === 'room_id' || $field === 'bed_id')) {
            [$line, $bed] = $this->positionOf($result->unitIndex, $unitsPerLine);

            return $field === 'room_id' ? ["lines.{$line}.room_id" => $message] : ["lines.{$line}.bed_ids.{$bed}" => $message];
        }

        if ($result->lineIndex !== null && in_array($field, self::LINE_FIELDS, true)) {
            return ["lines.{$result->lineIndex}.{$field}" => $message];
        }

        if ($result->lineIndex !== null && in_array($field, self::ADD_ON_FIELDS, true)) {
            return ["lines.{$result->lineIndex}.add_ons" => $message];
        }

        return [ReservationRefusals::FORM => $message];
    }

    /**
     * The field of a guest tab a refusal belongs to: the name, the unit it stays in, or the linked guest.
     */
    private function guestField(?string $refusal, string $field): string
    {
        return match ($refusal) {
            BookingResult::REFUSAL_GUEST_NAME_REQUIRED => 'full_name',
            AttachGuestResult::REFUSAL_BED_UNIT_FULL, AddOnResult::REFUSAL_UNIT_NOT_OPEN, BookingResult::REFUSAL_UNIT_NOT_FOUND => 'stays_in',
            default => $field === 'form' ? 'stays_in' : 'guest_id',
        };
    }

    /**
     * The line and the bed of a unit index, counting the units line by line.
     *
     * @param  list<int>  $unitsPerLine
     * @return array{0: int, 1: int}
     */
    private function positionOf(int $unitIndex, array $unitsPerLine): array
    {
        foreach ($unitsPerLine as $line => $units) {
            if ($unitIndex < $units) {
                return [$line, $unitIndex];
            }

            $unitIndex -= $units;
        }

        return [max(0, count($unitsPerLine) - 1), 0];
    }
}
