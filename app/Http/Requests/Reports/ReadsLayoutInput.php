<?php

namespace App\Http\Requests\Reports;

use App\Enums\ColumnAlign;
use App\Enums\PageOrientation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The layout fields of a report request: the orientation and the columns as `{key, visible, align}` in order. `align`
 * is optional; null means the code default. Whether the keys match the report is checked by
 * `ReportLayoutResolver::fromDraft()`.
 */
trait ReadsLayoutInput
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function layoutRules(): array
    {
        return [
            'orientation' => ['required', Rule::enum(PageOrientation::class)],
            'columns' => ['required', 'array', 'min:1', 'max:100'],
            'columns.*.key' => ['required', 'string', 'max:64'],
            'columns.*.visible' => ['required', 'boolean'],
            'columns.*.align' => ['nullable', Rule::enum(ColumnAlign::class)],
        ];
    }

    public function orientation(): PageOrientation
    {
        return PageOrientation::from($this->string('orientation')->toString());
    }

    /**
     * @return list<array{key: string, visible: bool, align: string|null}>
     */
    public function layoutColumns(): array
    {
        return array_values(array_map(
            fn (array $column): array => [
                'key' => (string) $column['key'],
                'visible' => filter_var($column['visible'], FILTER_VALIDATE_BOOLEAN),
                'align' => ($column['align'] ?? null) === null ? null : (string) $column['align'],
            ],
            $this->array('columns'),
        ));
    }
}
