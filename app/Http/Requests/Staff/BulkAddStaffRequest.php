<?php

namespace App\Http\Requests\Staff;

use App\Models\Property;
use App\Services\Staff\AssignableRoles;
use App\Services\Staff\StaffImportReader;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Every Bulk add row follows the single add rules. Errors are keyed per row, for example `rows.3.email`.
 */
class BulkAddStaffRequest extends FormRequest
{
    /**
     * The `can:staff.manage` route middleware authorizes this request.
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
                ? [...$row, 'name' => trim((string) ($row['name'] ?? '')), 'email' => Str::lower(trim((string) ($row['email'] ?? '')))]
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
        /** @var Property $property */
        $property = $this->route('property');

        return [
            'rows' => ['required', 'array', 'min:1', 'max:'.StaffImportReader::MAX_ROWS],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.email' => [
                'required', 'string', 'email', 'max:255', 'distinct',
                Rule::unique('users', 'email'),
                Rule::unique('staff_invitations', 'email')
                    ->where('property_id', $property->id)
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at'),
            ],
            'rows.*.role_id' => [
                'required', 'integer',
                Rule::in(app(AssignableRoles::class)->for($property, $this->user())->modelKeys()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.*.name.required' => __('Enter a name.'),
            'rows.*.email.required' => __('Enter an email.'),
            'rows.*.email.email' => __('Enter a valid email.'),
            'rows.*.email.distinct' => __('This email is in the list twice.'),
            'rows.*.email.unique' => __('This email already has an account or an open invitation.'),
            'rows.*.role_id.required' => __('Choose a role.'),
            'rows.*.role_id.in' => __('Choose a role you are allowed to give.'),
        ];
    }

    /**
     * @return list<array{name: string, email: string, role_id: int}>
     */
    public function rows(): array
    {
        return array_map(
            fn (array $row): array => ['name' => $row['name'], 'email' => $row['email'], 'role_id' => (int) $row['role_id']],
            array_values($this->validated('rows')),
        );
    }
}
