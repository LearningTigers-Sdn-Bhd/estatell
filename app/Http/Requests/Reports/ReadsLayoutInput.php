<?php

namespace App\Http\Requests\Reports;

use App\Enums\PageOrientation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The layout fields of a report request: the orientation and the columns as `{key, visible}` in order. Whether the
 * keys match the report is checked by `ReportLayoutResolver::fromDraft()`.
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
        ];
    }

    public function orientation(): PageOrientation
    {
        return PageOrientation::from($this->string('orientation')->toString());
    }

    /**
     * @return list<array{key: string, visible: bool}>
     */
    public function layoutColumns(): array
    {
        return array_values(array_map(
            fn (array $column): array => [
                'key' => (string) $column['key'],
                'visible' => filter_var($column['visible'], FILTER_VALIDATE_BOOLEAN),
            ],
            $this->array('columns'),
        ));
    }
}
