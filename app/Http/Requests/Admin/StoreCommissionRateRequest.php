<?php

namespace App\Http\Requests\Admin;

use App\Enums\CommissionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommissionRateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service' => ['required', Rule::enum(CommissionService::class)],
            'rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'effective_from' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:today',
                Rule::unique('commission_rates', 'effective_from')->where('service', $this->input('service')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'effective_from.after_or_equal' => __('Choose today or a later date.'),
            'effective_from.unique' => __('This service already has a rate from that date.'),
        ];
    }

    public function service(): CommissionService
    {
        return CommissionService::from($this->validated('service'));
    }

    public function effectiveFrom(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('effective_from'));
    }
}
