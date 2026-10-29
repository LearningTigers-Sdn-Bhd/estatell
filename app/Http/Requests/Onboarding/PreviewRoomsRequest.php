<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The numbers fields of the Room numbers table as they are on screen, for the parsed numbers preview. A blank or
 * half-typed field is allowed: the preview returns its error. The `onboarding.only` middleware limits who can reach
 * this route.
 */
class PreviewRoomsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'max:'.SaveRoomsRequest::MAX_GROUPS],
            'rows.*' => ['array'],
            'rows.*.numbers' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<string>
     */
    public function lists(): array
    {
        return array_values(array_map(
            fn (array $row): string => trim((string) ($row['numbers'] ?? '')),
            $this->validated('rows'),
        ));
    }
}
