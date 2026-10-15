<?php

namespace App\Enums;

/**
 * The state of one onboarding step, calculated from real data by OnboardingProgress. Nothing is stored.
 */
enum StepState: string
{
    case Locked = 'locked';
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Done = 'done';
    case NeedsAttention = 'needs_attention';

    public function label(): string
    {
        return match ($this) {
            self::Locked => __('Locked'),
            self::NotStarted => __('Not started'),
            self::InProgress => __('In progress'),
            self::Done => __('Done'),
            self::NeedsAttention => __('Needs attention'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Locked, self::NotStarted => Tone::Neutral,
            self::InProgress => Tone::Info,
            self::Done => Tone::Success,
            self::NeedsAttention => Tone::Warning,
        };
    }
}
