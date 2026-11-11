<?php

namespace App\Http\Requests\Admin;

use App\Enums\CommissionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartCommissionPeriodRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service' => ['required', Rule::enum(CommissionService::class)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function service(): CommissionService
    {
        return CommissionService::from($this->validated('service'));
    }

    public function startsOn(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('starts_on'));
    }
}
