<?php

namespace App\Enums;

/**
 * The state of one night audit. A Failed audit is resumed by running the audit again. See docs/20-night-audit/03-audit-run.md.
 */
enum AuditStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => __('Running'),
            self::Completed => __('Completed'),
            self::Failed => __('Failed'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Running => Tone::Info,
            self::Completed => Tone::Success,
            self::Failed => Tone::Danger,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, match ($this) {
            self::Running => [self::Completed, self::Failed],
            self::Failed => [self::Running],
            self::Completed => [],
        }, true);
    }
}
