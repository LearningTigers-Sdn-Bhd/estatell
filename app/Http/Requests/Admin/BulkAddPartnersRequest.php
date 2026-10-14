<?php

namespace App\Http\Requests\Admin;

use App\Concerns\PartnerValidationRules;
use App\Data\Platform\NewPartnerData;
use App\Services\Platform\PartnerImportReader;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Every Bulk add row follows the single add rules. Errors are keyed per row, for example `rows.3.code_prefix`.
 */
class BulkAddPartnersRequest extends FormRequest
{
    use PartnerValidationRules;

    /**
     * The `can:admin` route middleware authorizes this request.
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
                    ...$row,
                    'partner_name' => trim((string) ($row['partner_name'] ?? '')),
                    'code_prefix' => Str::upper(trim((string) ($row['code_prefix'] ?? ''))),
                    'name' => trim((string) ($row['name'] ?? '')),
                    'email' => Str::lower(trim((string) ($row['email'] ?? ''))),
                ]
                : $row,
            $rows,
        )]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1', 'max:'.PartnerImportReader::MAX_ROWS],
            'rows.*.partner_name' => ['required', 'string', 'max:255'],
            'rows.*.code_prefix' => [...$this->partnerPrefixRules(), 'distinct'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.email' => [...$this->partnerEmailRules(), 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.*.partner_name.required' => __('Enter a partner name.'),
            'rows.*.name.required' => __('Enter a name.'),
            'rows.*.email.required' => __('Enter an email.'),
            'rows.*.email.email' => __('Enter a valid email.'),
            'rows.*.code_prefix.required' => __('Enter a prefix.'),
            'rows.*.code_prefix.distinct' => __('This prefix is in the list twice.'),
            'rows.*.email.distinct' => __('This email is in the list twice.'),
            ...$this->partnerPrefixMessages('rows.*.code_prefix'),
        ];
    }

    /**
     * @return list<NewPartnerData>
     */
    public function toPartnerRows(): array
    {
        return array_map(
            fn (array $row): NewPartnerData => new NewPartnerData(
                partnerName: $row['partner_name'],
                codePrefix: $row['code_prefix'],
                name: $row['name'],
                email: $row['email'],
            ),
            array_values($this->validated('rows')),
        );
    }
}
