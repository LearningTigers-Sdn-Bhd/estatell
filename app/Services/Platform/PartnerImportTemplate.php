<?php

namespace App\Services\Platform;

use App\Enums\SpreadsheetFormat;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * The empty file admins fill in for partner Bulk add: a header row of partner name, prefix, user name and email.
 */
class PartnerImportTemplate
{
    /**
     * Write the template to a temporary file and return its path. The caller deletes it after sending.
     */
    public function write(SpreadsheetFormat $format): string
    {
        $path = sys_get_temp_dir().'/'.Str::uuid().'.'.$format->value;

        SimpleExcelWriter::create($path)
            ->addHeader(PartnerImportReader::COLUMNS)
            ->close();

        return $path;
    }
}
