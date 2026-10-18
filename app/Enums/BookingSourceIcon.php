<?php

namespace App\Enums;

/**
 * The icons a booking source badge can show. The value is the Lucide icon name.
 */
enum BookingSourceIcon: string
{
    case Phone = 'phone';
    case MessageCircle = 'message-circle';
    case Mail = 'mail';
    case Globe = 'globe';
    case Users = 'users';
    case Handshake = 'handshake';
    case BriefcaseBusiness = 'briefcase-business';
    case Building2 = 'building-2';
    case Footprints = 'footprints';
    case Ticket = 'ticket';
    case Megaphone = 'megaphone';
    case Star = 'star';

    /**
     * The accessible name of the icon in the badge picker.
     */
    public function label(): string
    {
        return match ($this) {
            self::Phone => __('Phone'),
            self::MessageCircle => __('Chat'),
            self::Mail => __('Email'),
            self::Globe => __('Website'),
            self::Users => __('People'),
            self::Handshake => __('Partner'),
            self::BriefcaseBusiness => __('Business'),
            self::Building2 => __('Office'),
            self::Footprints => __('Walk-in'),
            self::Ticket => __('Voucher'),
            self::Megaphone => __('Campaign'),
            self::Star => __('Star'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $icon): array => ['value' => $icon->value, 'label' => $icon->label()],
            self::cases(),
        );
    }
}
