<?php

namespace App\Services\Staff;

use App\Data\Staff\TemporaryPasswordSlipData;
use App\Enums\TemporaryPasswordExportFormat;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Writes temp password slips to a CSV, XLSX or PDF file (8 cut-out slips per A4 page).
 */
class TemporaryPasswordFile
{
    /**
     * Write the file to a temporary path and return it. The caller deletes it after sending.
     *
     * @param  Collection<int, TemporaryPasswordSlipData>  $slips
     * @param  string|null  $nameColumnLabel  the header of the first column of a CSV or XLSX file; defaults to "Property"
     */
    public function write(Collection $slips, TemporaryPasswordExportFormat $format, ?string $nameColumnLabel = null): string
    {
        if (! $format->isFile()) {
            throw new InvalidArgumentException('The copy message is not a file.');
        }

        $path = sys_get_temp_dir().'/'.Str::uuid().'.'.$format->value;

        if ($format === TemporaryPasswordExportFormat::Pdf) {
            Pdf::loadView('pdf.temporary-password-slips', ['slips' => $slips->values()])
                ->setPaper('a4', 'portrait')
                ->setOption('isFontSubsettingEnabled', true)
                ->save($path);

            return $path;
        }

        $nameColumnLabel ??= __('Property');

        SimpleExcelWriter::create($path)
            ->addRows($slips->map(fn (TemporaryPasswordSlipData $slip): array => [
                $nameColumnLabel => $slip->property,
                __('Name') => $slip->name,
                __('Email') => $slip->email,
                __('Role') => $slip->role ?? '',
                __('Temp password') => $slip->temporaryPassword,
                __('Expires') => $slip->expiresOn,
                __('Login URL') => $slip->loginUrl,
            ]))
            ->close();

        return $path;
    }
}
