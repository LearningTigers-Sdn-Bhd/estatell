<?php

namespace App\Http\Requests\CityLedger;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRatePlansRequest extends FormRequest
{
    /**
     * The `can:company.manage` route middleware authorizes this request.
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
            'rate_plan_ids' => ['nullable', 'array', 'max:200'],
            'rate_plan_ids.*' => ['integer'],
        ];
    }

    /**
     * @return list<int>
     */
    public function ratePlanIds(): array
    {
        return array_map(intval(...), $this->validated('rate_plan_ids') ?? []);
    }
}
