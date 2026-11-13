<?php

namespace App\Enums;

/**
 * Where a guest is on the Concierge page. The page shows one step at a time and `ConciergeProgress` picks it.
 */
enum ConciergeStep: string
{
    case Identity = 'identity';
    case Card = 'card';
    case Payment = 'payment';
    case CheckIn = 'check_in';
    case Key = 'key';
    case Home = 'home';
    case CheckedOut = 'checked_out';
    case NotOpen = 'not_open';
    case HoldExpired = 'hold_expired';
    case Desk = 'desk';

    public function label(): string
    {
        return match ($this) {
            self::Identity => __('Your details'),
            self::Card => __('Registration card'),
            self::Payment => __('Payment'),
            self::CheckIn => __('Check in'),
            self::Key => __('Your room key'),
            self::Home => __('Your stay'),
            self::CheckedOut => __('Checked out'),
            self::NotOpen => __('Check-in is not open yet'),
            self::HoldExpired => __('Your room hold has ended'),
            self::Desk => __('Please see the front desk'),
        };
    }
}
