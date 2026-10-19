<?php

namespace App\Http\Requests\Rates;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RatePlanCompanyRowRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
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
            'at_desk' => ['required', 'boolean'],
            'in_portal' => ['required', 'boolean'],
        ];
    }
}
