<?php

namespace App\Enums;

/** The meal periods every property starts with. Used for seeding and for prefilling the inclusion form. */
enum DefaultMealPeriod: string
{
    case Breakfast = 'breakfast';
    case Lunch = 'lunch';
    case Dinner = 'dinner';

    public function label(): string
    {
        return match ($this) {
            self::Breakfast => __('Breakfast'),
            self::Lunch => __('Lunch'),
            self::Dinner => __('Dinner'),
        };
    }

    public function startsAt(): string
    {
        return match ($this) {
            self::Breakfast => '07:00',
            self::Lunch => '12:00',
            self::Dinner => '19:00',
        };
    }

    public function endsAt(): string
    {
        return match ($this) {
            self::Breakfast => '10:00',
            self::Lunch => '14:30',
            self::Dinner => '22:00',
        };
    }

    /** The first date the inclusion form prefills for this meal. */
    public function inclusionStart(): InclusionStart
    {
        return match ($this) {
            self::Breakfast => InclusionStart::DayAfterArrival,
            self::Lunch, self::Dinner => InclusionStart::ArrivalDay,
        };
    }

    /** The last date the inclusion form prefills for this meal. */
    public function inclusionEnd(): InclusionEnd
    {
        return match ($this) {
            self::Breakfast, self::Lunch => InclusionEnd::DepartureDay,
            self::Dinner => InclusionEnd::NightBeforeDeparture,
        };
    }

    /** The default meal a property's meal period name stands for, if it still has a default name. */
    public static function fromName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if (mb_strtolower(trim($name)) === mb_strtolower($case->label())) {
                return $case;
            }
        }

        return null;
    }
}
