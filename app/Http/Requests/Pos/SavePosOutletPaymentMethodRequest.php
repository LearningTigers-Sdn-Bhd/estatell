<?php

namespace App\Http\Requests\Pos;

use App\Enums\PosTenderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePosOutletPaymentMethodRequest extends FormRequest
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
            'payment_method_id' => ['required', 'integer'],
            'tender_type' => ['required', Rule::in(array_map(fn (array $option): string => $option['value'], PosTenderType::outletOptions()))],
            'pulse_drawer' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }
}
