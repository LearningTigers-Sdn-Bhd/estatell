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
    case Each = 'each';

    /** Each reads "Per item" or "Per person", following how the facility is booked. */
    public function label(FacilityBookedBy $bookedBy = FacilityBookedBy::Item): string
    {
        return match ($this) {
            self::Day => __('Per day'),
            self::Session => __('Per session'),
            self::Hour => __('Per hour'),
            self::Each => $bookedBy === FacilityBookedBy::Person ? __('Per person') : __('Per item'),
        };
    }

    /**
     * How many units of the price a booking is charged for: Day and Session 1, Hour the booked hours rounded up,
     * Each the booking quantity (items or people). Times are `HH:MM` or `HH:MM:SS` on one day.
     */
    public function quantity(string $startsAt, string $endsAt, int $quantity): int
    {
        return match ($this) {
            self::Day, self::Session => 1,
            self::Hour => (int) ceil((self::minutes($endsAt) - self::minutes($startsAt)) / 60),
            self::Each => $quantity,
        };
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(FacilityBookedBy $bookedBy = FacilityBookedBy::Item): array
    {
        return array_map(
            fn (self $basis): array => ['value' => $basis->value, 'label' => $basis->label($bookedBy)],
            self::cases(),
        );
    }

    /**
     * The Per select for each Booked by choice, so the form can relabel Each when staff switch.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function optionsByBookedBy(): array
    {
        return collect(FacilityBookedBy::cases())
            ->mapWithKeys(fn (FacilityBookedBy $bookedBy): array => [$bookedBy->value => self::options($bookedBy)])
            ->all();
    }
}
