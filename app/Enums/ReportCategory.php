<?php

namespace App\Enums;

/**
 * The group a report sits under in the Reports sidebar.
 */
enum ReportCategory: string
{
    case FrontDesk = 'front-desk';
    case Revenue = 'revenue';
    case Finance = 'finance';
    case Kitchen = 'kitchen';
    case Audit = 'audit';

    public function label(): string
    {
        return match ($this) {
            self::FrontDesk => __('Front desk'),
            self::Revenue => __('Revenue'),
            self::Finance => __('Finance'),
            self::Kitchen => __('Kitchen'),
            self::Audit => __('Audit'),
        };
    }
}
