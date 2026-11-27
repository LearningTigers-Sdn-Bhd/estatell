<?php

namespace App\Http\Requests\Transfers;

use App\Enums\ReportKey;
use App\Enums\SpreadsheetFormat;
use App\Http\Requests\Reports\ExportReportRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The input of a run sheet export: the `date`, the transfer ids as `rowKeys` and, for the spreadsheet, a `format`. It is
 * the shared export request with the report fixed to the Transfer manifest. The `transfers` and `reservations.view`
 * route middleware authorize it.
 */
class ExportTransferManifestRequest extends ExportReportRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => [Rule::requiredIf(fn (): bool => $this->routeIs('transfers.spreadsheet')), Rule::enum(SpreadsheetFormat::class)],
        ];
    }

    public function reportKey(): ReportKey
    {
        return ReportKey::TransferManifest;
    }
}
