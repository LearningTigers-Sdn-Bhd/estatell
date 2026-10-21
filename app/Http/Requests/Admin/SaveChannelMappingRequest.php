<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The map page: one line for each occupancy of a Channex rate plan. A line with no `ota` is unmapped. The OTA's own
 * codes are checked against a fresh read of the OTA in the Action. The `can:admin` route middleware authorizes this
 * request.
 */
class SaveChannelMappingRequest extends FormRequest
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
            'lines' => ['present', 'array', 'max:2000'],
            'lines.*.rate_plan_mapping_id' => ['required', 'integer'],
            'lines.*.occupancy' => ['required', 'integer', 'min:1', 'max:30'],
            'lines.*.ota' => ['nullable', 'string', 'max:100'],
            'lines.*.is_primary' => ['sometimes', 'boolean'],
            'lines.*.rate_type' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return list<array{rate_plan_mapping_id: int, occupancy: int, ota: string|null, is_primary?: bool, rate_type?: string|null}>
     */
    public function lines(): array
    {
        return array_values($this->validated('lines'));
    }
}
