<?php

namespace App\Services\CityLedger;

use App\Enums\CompanyImportMode;
use App\Enums\SpreadsheetFormat;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * The empty file staff fill in for Bulk add companies: the header row of the chosen mode.
 */
class CompanyImportTemplate
{
    /**
     * Write the template to a temporary file and return its path. The caller deletes it after sending.
     */
    public function write(SpreadsheetFormat $format, CompanyImportMode $mode): string
    {
        $path = sys_get_temp_dir().'/'.Str::uuid().'.'.$format->value;

        SimpleExcelWriter::create($path)
            ->addHeader(array_keys($mode->columns()))
            ->close();

        return $path;
    }
}
