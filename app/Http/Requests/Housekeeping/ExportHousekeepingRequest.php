<?php

namespace App\Http\Requests\Housekeeping;

use App\Enums\ReportKey;
use App\Enums\SpreadsheetFormat;
use App\Http\Requests\Reports\ExportReportRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The input of a board export: the `date`, the table `sort`, the selected room ids as `rowKeys` and, for the
 * spreadsheet, a `format`. It is the shared export request with the report fixed to Housekeeping tasks. The
 * `housekeeping.view` route middleware authorizes it.
 */
class ExportHousekeepingRequest extends ExportReportRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => [Rule::requiredIf(fn (): bool => $this->routeIs('housekeeping.spreadsheet')), Rule::enum(SpreadsheetFormat::class)],
        ];
    }

    public function reportKey(): ReportKey
    {
        return ReportKey::HousekeepingTasks;
    }
}
