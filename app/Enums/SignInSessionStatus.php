<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Where the session that a successful sign-in started stands now. See docs/01-platform/07-sign-in-log.md.
 */
enum SignInSessionStatus: string
{
    case Active = 'active';
    case Idle = 'idle';
    case SignedOut = 'signed_out';
    case Ended = 'ended';

    /**
     * Minutes without a request before an open session counts as idle.
     */
    public const int IDLE_AFTER_MINUTES = 5;

    /**
     * Signed out wins. Otherwise the last request decides: within 5 minutes is active, within the session lifetime is
     * idle, and older has expired.
     */
    public static function at(?CarbonImmutable $lastActiveAt, ?CarbonImmutable $signedOutAt, CarbonImmutable $now): ?self
    {
        return match (true) {
            $signedOutAt !== null => self::SignedOut,
            $lastActiveAt === null => null,
            $lastActiveAt->gt($now->subMinutes(self::IDLE_AFTER_MINUTES)) => self::Active,
            $lastActiveAt->gt($now->subMinutes(config()->integer('session.lifetime'))) => self::Idle,
            default => self::Ended,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Idle => __('Idle'),
            self::SignedOut => __('Signed out'),
            self::Ended => __('Session expired'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Active => Tone::Success,
            self::Idle => Tone::Warning,
            self::SignedOut, self::Ended => Tone::Neutral,
        };
    }
}
