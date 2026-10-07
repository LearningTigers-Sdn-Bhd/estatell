<?php

namespace App\Enums;

/**
 * What started a night audit. See docs/20-night-audit/03-audit-run.md.
 */
enum AuditTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => __('Scheduled'),
            self::Manual => __('Run by staff'),
        };
    }
}
