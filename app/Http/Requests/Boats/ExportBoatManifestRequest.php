<?php

namespace App\Http\Requests\Boats;

use App\Enums\ReportKey;
use App\Enums\SpreadsheetFormat;
use App\Http\Requests\Reports\ExportReportRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The input of a Boat Trips export: the `date`, the `direction` (`in`, `out` or `both`), the ticked trip ids as `rowKeys`
 * and, for the spreadsheet, a `format`. It is the shared export request with the report fixed to the Boat manifest. The
 * `boats` and `reservations.view` route middleware authorize it.
 */
class ExportBoatManifestRequest extends ExportReportRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => [Rule::requiredIf(fn (): bool => $this->routeIs('boats.spreadsheet')), Rule::enum(SpreadsheetFormat::class)],
        ];
    }

    public function reportKey(): ReportKey
    {
        return ReportKey::BoatManifest;
    }
}
