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
     * How many units of the price a booking is charged for. A facility booked by item charges every item it takes:
     * 3 kayaks for 2 hours is 6 hours. A facility booked by person charges Day, Session and Hour once for the group,
     * and Each per person. Hours are rounded up. Times are `HH:MM` or `HH:MM:SS` on one day. A private booking (a charter)
     * has one price for the booking, so it is charged once.
     */
    public function quantity(string $startsAt, string $endsAt, int $quantity, FacilityBookedBy $bookedBy, bool $private = false): int
    {
        if ($private) {
            return 1;
        }

        return $this->timeUnits($startsAt, $endsAt) * ($this->countsQuantity($bookedBy) ? $quantity : 1);
    }

    /**
     * How the charge adds up, without the price, for example "3 items × 2 hours", "4 people" or "Private · 6 people".
     */
    public function breakdown(string $startsAt, string $endsAt, int $quantity, FacilityBookedBy $bookedBy, bool $private = false): string
    {
        if ($private) {
            return __('Private · :count :unit', ['count' => $quantity, 'unit' => $bookedBy->unit($quantity)]);
        }

        $parts = [];

        if ($this->countsQuantity($bookedBy)) {
            $parts[] = $quantity.' '.$bookedBy->unit($quantity);
        }

        if ($this !== self::Each) {
            $units = $this->timeUnits($startsAt, $endsAt);
            $parts[] = $units.' '.match ($this) {
                self::Day => $units === 1 ? __('day') : __('days'),
                self::Session => $units === 1 ? __('session') : __('sessions'),
                default => $units === 1 ? __('hour') : __('hours'),
            };
        }

        return implode(' × ', $parts);
    }

    /** Whether the booking quantity multiplies the price: always for items, and for people only when priced Each. */
    private function countsQuantity(FacilityBookedBy $bookedBy): bool
    {
        return $this === self::Each || $bookedBy === FacilityBookedBy::Item;
    }

    /** Hours for Hour, otherwise 1. */
    private function timeUnits(string $startsAt, string $endsAt): int
    {
        return $this === self::Hour ? (int) ceil((self::minutes($endsAt) - self::minutes($startsAt)) / 60) : 1;
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
