<?php

namespace App\Enums;

/**
 * How an owner hands out temp passwords: a copy message, or a file (added with the export commit).
 */
enum TemporaryPasswordExportFormat: string
{
    case Message = 'message';

    public function label(): string
    {
        return match ($this) {
            self::Message => __('Copy message'),
        };
    }
}
