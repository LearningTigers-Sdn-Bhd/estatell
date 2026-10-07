<?php

namespace App\Enums;

/**
 * The state of one step of a night audit. A Completed step is skipped when the audit is resumed.
 */
enum AuditStepStatus: string
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
}
