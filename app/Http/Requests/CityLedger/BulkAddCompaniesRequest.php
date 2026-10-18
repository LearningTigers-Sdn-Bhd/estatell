<?php

namespace App\Http\Requests\CityLedger;

use App\Models\Property;
use App\Services\CityLedger\CompanyImportReader;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Every Bulk add row follows the add sheet and the tax and billing address rules. Errors are keyed per row, for
 * example `rows.3.name`.
 */
class BulkAddCompaniesRequest extends FormRequest
{
    /**
     * The `can:company.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');

        if (! is_array($rows)) {
            return;
        }

        $this->merge(['rows' => array_map(
            fn (mixed $row): mixed => is_array($row)
                ? [
                    ...array_map(fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), $row),
                    'name' => trim((string) ($row['name'] ?? '')),
                    'contact_email' => filled($row['contact_email'] ?? null) ? mb_strtolower(trim((string) $row['contact_email'])) : null,
                    'portal_email' => filled($row['portal_email'] ?? null) ? mb_strtolower(trim((string) $row['portal_email'])) : null,
                    'direct_bill_enabled' => filter_var($row['direct_bill_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                ]
                : $row,
            $rows,
        )]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['rows' => ['required', 'array', 'min:1', 'max:'.CompanyImportReader::MAX_ROWS]];

        foreach (CompanyRules::for($this->property()) as $field => $fieldRules) {
            $rules["rows.*.{$field}"] = in_array($field, ['name', 'portal_email'], true) ? [...$fieldRules, 'distinct:ignore_case'] : $fieldRules;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.*.name.required' => __('Enter a name.'),
            'rows.*.name.distinct' => __('This name is in the list twice.'),
            'rows.*.portal_email.distinct' => __('This portal login email is in the list twice.'),
            'rows.*.type.required' => __('Choose a type.'),
        ];
    }

    /**
     * The validated rows for the model, with phone numbers in E.164.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $fields = array_keys(CompanyRules::for($this->property()));

        return array_map(
            fn (array $row): array => CompanyRules::normalized(
                array_merge(array_fill_keys($fields, null), array_intersect_key($row, array_flip($fields))),
                $this->property(),
            ),
            array_values($this->validated('rows')),
        );
    }

    private function property(): Property
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return $property;
    }
}
