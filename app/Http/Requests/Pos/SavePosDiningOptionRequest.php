<?php

namespace App\Http\Requests\Pos;

use App\Enums\PosDiningKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class SavePosDiningOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'kind' => ['required', Rule::enum(PosDiningKind::class)],
            'apply_service_charge' => ['required', 'boolean'],
            'service_charge_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'service_charge_taxable' => ['required', 'boolean'],
            'packaging_fee' => ['required', 'decimal:0,2', 'min:0'],
            'is_default' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ];
    }
}
