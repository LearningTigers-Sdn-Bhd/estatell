<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff save the Loyverse store and payment type mappings. The route's `can:` middleware checks `pos.manage`.
 */
class SaveLoyverseMappingsRequest extends FormRequest
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
            'mappings' => ['present', 'array', 'max:100'],
            'mappings.*.store_id' => ['required', 'string', 'max:100'],
            'mappings.*.pos_outlet_id' => ['nullable', 'integer'],
            'mappings.*.payment_type_id' => ['nullable', 'string', 'max:100'],
            'mappings.*.active' => ['required', 'boolean'],
        ];
    }
}
