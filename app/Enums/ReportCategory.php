<?php

namespace App\Enums;

/**
 * The group a report sits under in the Reports sidebar.
 */
enum ReportCategory: string
{
    case FrontDesk = 'front-desk';

    public function label(): string
    {
        return match ($this) {
            self::FrontDesk => __('Front desk'),
        };
    }
}
