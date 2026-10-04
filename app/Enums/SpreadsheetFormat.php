<?php

namespace App\Enums;

/**
 * Spreadsheet files staff can upload or download.
 */
enum SpreadsheetFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function label(): string
    {
        return match ($this) {
            self::Csv => __('CSV'),
            self::Xlsx => __('Excel (XLSX)'),
        };
    }
}
