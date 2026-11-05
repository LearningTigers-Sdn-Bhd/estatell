<?php

namespace App\Services\Reservations;

use App\Enums\SpreadsheetFormat;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * The empty arrival import file: the estatell columns and one example row. See docs/12-reservations/13-arrival-import.md#files.
 */
class ArrivalImportTemplate
{
    /**
     * Write the template to a temporary file and return its path. The caller deletes it after sending.
     */
    public function write(SpreadsheetFormat $format): string
    {
        $path = sys_get_temp_dir().'/'.Str::uuid().'.'.$format->value;

        SimpleExcelWriter::create($path)
            ->addHeader(ArrivalImportReader::COLUMNS)
            ->addRow(array_combine(ArrivalImportReader::COLUMNS, [
                'RES1001-1', 'Jane Tan', 'A10', 'Deluxe King', '2026-12-20 15:00', '2026-12-22 11:00', '2', '0', '1560.00', '500.00', '', 'Phone', 'confirmed', '10:30', 'OWN BOAT', 'Late check-in',
            ]))
            ->close();

        return $path;
    }
}
