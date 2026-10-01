<?php

namespace App\Enums;

/**
 * The status of a reservation, stored on `reservations.status`. It is derived from its units by `fromUnits()` and
 * written only by `SyncReservationStatus`. It has no `canTransitionTo()` because nothing sets it by hand: the unit
 * statuses own the transition rules (see `UnitStatus`). See docs/12-reservations/01-reservation-shape.md.
 */
enum ReservationStatus: string
{
    case Confirmed = 'confirmed';
    case InHouse = 'in_house';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Derive the reservation status from its unit statuses. Live units are the ones that are not Cancelled or NoShow.
     *
     * 1. Every unit is Cancelled: Cancelled.
     * 2. No live unit is left (only NoShow and Cancelled): Completed.
     * 3. A live unit is CheckedIn: InHouse.
     * 4. Every live unit is CheckedOut: Completed.
     * 5. Anything else: Confirmed. A group with some units checked out and others still to arrive stays Confirmed.
     *
     * @param  iterable<UnitStatus>  $units
     */
    public static function fromUnits(iterable $units): self
    {
        $statuses = [];

        foreach ($units as $unit) {
            $statuses[] = $unit;
        }

        if ($statuses === []) {
            return self::Confirmed;
        }

        if (array_all($statuses, fn (UnitStatus $status): bool => $status === UnitStatus::Cancelled)) {
            return self::Cancelled;
        }

        $live = array_filter($statuses, fn (UnitStatus $status): bool => ! in_array($status, [UnitStatus::Cancelled, UnitStatus::NoShow], true));

        return match (true) {
            $live === [] => self::Completed,
            in_array(UnitStatus::CheckedIn, $live, true) => self::InHouse,
            array_all($live, fn (UnitStatus $status): bool => $status === UnitStatus::CheckedOut) => self::Completed,
            default => self::Confirmed,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => __('Confirmed'),
            self::InHouse => __('In house'),
            self::Completed => __('Completed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Confirmed => Tone::Info,
            self::InHouse => Tone::Success,
            self::Completed => Tone::Neutral,
            self::Cancelled => Tone::Danger,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
