<?php

namespace App\Enums;

/**
 * What staff archived or unlinked that the channel manager still has, so it must be removed there first.
 */
enum ChannelRemovalKind: string
{
    case RatePlan = 'rate_plan';
    case RoomType = 'room_type';
    case Unlink = 'unlink';

    public function label(): string
    {
        return match ($this) {
            self::RatePlan => __('Rate plan'),
            self::RoomType => __('Room type'),
            self::Unlink => __('Room types of a rate plan'),
        };
    }
}
