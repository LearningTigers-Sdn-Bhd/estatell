<?php

namespace App\Http\Requests\Rates;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddRatePlanCompaniesRequest extends FormRequest
{
    /**
     * The `can:settings.manage` and `can:company.manage` route middleware authorize this request.
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
            'company_ids' => ['required', 'array', 'min:1', 'max:200'],
            'company_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function companyIds(): array
    {
        return array_values(array_map(intval(...), $this->validated('company_ids')));
    }
}
