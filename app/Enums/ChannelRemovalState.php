<?php

namespace App\Enums;

/**
 * Where an archive or unlink that waits for the channel manager stands, for the staff member who asked.
 */
enum ChannelRemovalState: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Removing from the channels'),
            self::Done => __('Done'),
            self::Blocked => __('Not done'),
        };
    }
}
