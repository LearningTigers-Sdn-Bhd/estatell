<?php

namespace App\Http\Requests\Admin;

use App\Data\Commissions\CommissionBookingFilters;
use App\Enums\CommissionPeriod;
use App\Enums\CommissionService;
use App\Services\Commissions\PayoutCycle;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommissionBookingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filter.period' => ['nullable', Rule::enum(CommissionPeriod::class)],
            'filter.date' => ['nullable', 'date_format:Y-m-d'],
            'filter.property' => ['nullable', 'integer'],
            'filter.service' => ['nullable', Rule::enum(CommissionService::class)],
            'filter.search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * The filters from the URL. With none, this month in Malaysia time.
     */
    public function filters(): CommissionBookingFilters
    {
        $date = $this->validated('filter.date');

        return CommissionBookingFilters::make(
            CommissionPeriod::tryFrom((string) $this->validated('filter.period')) ?? CommissionPeriod::Month,
            $date === null ? app(PayoutCycle::class)->today() : CarbonImmutable::createFromFormat('!Y-m-d', $date),
            $this->validated('filter.property') === null ? null : (int) $this->validated('filter.property'),
            CommissionService::tryFrom((string) $this->validated('filter.service')),
            $this->validated('filter.search'),
        );
    }
}
