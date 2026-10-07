<?php

namespace App\Http\Requests\Reports;

use App\Enums\SpreadsheetFormat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The input of a report export: the dates (see `ReportFiltersRequest`), the table `sort`, and the keys of the selected
 * rows (at most 500; none means every row that matches the dates). The spreadsheet also needs a `format`.
 */
class ExportReportRequest extends ReportFiltersRequest
{
    public const int MAX_ROW_KEYS = 500;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'sort' => ['nullable', 'string', 'max:64'],
            'rowKeys' => ['nullable', 'array', 'max:'.self::MAX_ROW_KEYS],
            'rowKeys.*' => ['required', fn (string $attribute, mixed $value, callable $fail) => is_int($value) || is_string($value) ? null : $fail(__('A row key must be a number or text.'))],
            'format' => [Rule::requiredIf(fn (): bool => $this->routeIs('reports.spreadsheet')), Rule::enum(SpreadsheetFormat::class)],
        ];
    }

    public function sortKey(): string
    {
        return $this->string('sort')->toString();
    }

    /**
     * The selected row keys, or null to export every row that matches the dates.
     *
     * @return list<int|string>|null
     */
    public function rowKeys(): ?array
    {
        $keys = $this->validated('rowKeys') ?? [];

        return $keys === [] ? null : array_values($keys);
    }

    public function spreadsheetFormat(): SpreadsheetFormat
    {
        return SpreadsheetFormat::from($this->validated('format'));
    }
}
