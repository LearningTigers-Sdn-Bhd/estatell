<?php

namespace App\Enums;

/** The meal periods every property starts with. Used for seeding only. */
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
}
