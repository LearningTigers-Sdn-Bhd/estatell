<?php

namespace App\Services\Staff;

use App\Enums\SpreadsheetFormat;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * The empty file owners fill in for Bulk add: a header row of name, email and role.
 */
class StaffImportTemplate
{
    /**
     * Write the template to a temporary file and return its path. The caller deletes it after sending.
     */
    public function write(SpreadsheetFormat $format): string
    {
        $path = sys_get_temp_dir().'/'.Str::uuid().'.'.$format->value;

        SimpleExcelWriter::create($path)
            ->addHeader(StaffImportReader::COLUMNS)
            ->close();

        return $path;
    }
}
