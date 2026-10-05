<?php

namespace App\Enums;

/**
 * The state of one registration card. A signed card is never edited, only voided.
 */
enum RegistrationCardStatus: string
{
    case Draft = 'draft';
    case Signed = 'signed';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Signed => __('Signed'),
            self::Void => __('Void'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Draft => Tone::Warning,
            self::Signed => Tone::Success,
            self::Void => Tone::Neutral,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::Signed, self::Void], true),
            self::Signed => $next === self::Void,
            self::Void => false,
        };
    }
}
