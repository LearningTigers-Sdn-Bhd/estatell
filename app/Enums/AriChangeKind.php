<?php

namespace App\Enums;

/**
 * What an outbox change in `channel_ari_changes` asks the flush to send again.
 */
enum AriChangeKind: string
{
    case Availability = 'availability';
    case Rates = 'rates';
    case Restrictions = 'restrictions';

    public function label(): string
    {
        return match ($this) {
            self::Availability => __('Availability'),
            self::Rates => __('Rates'),
            self::Restrictions => __('Restrictions'),
        };
    }

    /**
     * Rates and restrictions travel in one `/restrictions` call. Availability has its own `/availability` call.
     */
    public function isRestrictionCall(): bool
    {
        return $this !== self::Availability;
    }
}
