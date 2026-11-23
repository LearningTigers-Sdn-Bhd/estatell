<?php

namespace App\Enums;

/**
 * What a facility's price is for. `quantity()` turns a booking into the number of units it is charged for.
 * See docs/33-facilities/02-facility-settings.md#enums.
 */
enum FacilityRateBasis: string
{
    case Day = 'day';
    case Session = 'session';
    case Hour = 'hour';
    case Person = 'person';

    public function label(): string
    {
        return match ($this) {
            self::Day => __('Per day'),
            self::Session => __('Per session'),
            self::Hour => __('Per hour'),
            self::Person => __('Per person'),
        };
    }

    /**
     * How many units of the price a booking is charged for: Day and Session 1, Hour the booked hours rounded up,
     * Person the booking quantity. Times are `HH:MM` or `HH:MM:SS` on one day.
     */
    public function quantity(string $startsAt, string $endsAt, int $quantity): int
    {
        return match ($this) {
            self::Day, self::Session => 1,
            self::Hour => (int) ceil((self::minutes($endsAt) - self::minutes($startsAt)) / 60),
            self::Person => $quantity,
        };
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $basis): array => ['value' => $basis->value, 'label' => $basis->label()],
            self::cases(),
        );
    }
}
