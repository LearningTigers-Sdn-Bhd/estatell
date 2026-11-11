<?php

namespace App\Http\Requests\Payouts;

use App\Data\Commissions\CommissionBookingFilters;
use App\Enums\CommissionPeriod;
use App\Enums\CommissionService;
use App\Models\Property;
use App\Services\Commissions\PayoutCycle;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayoutBookingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filter.period' => ['nullable', Rule::enum(CommissionPeriod::class)],
            'filter.date' => ['nullable', 'date_format:Y-m-d'],
            'filter.service' => ['nullable', Rule::enum(CommissionService::class)],
            'filter.search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * The filters from the URL, always for the property in the route. With none, this month in Malaysia time.
     */
    public function filters(Property $property): CommissionBookingFilters
    {
        $date = $this->validated('filter.date');

        return CommissionBookingFilters::make(
            CommissionPeriod::tryFrom((string) $this->validated('filter.period')) ?? CommissionPeriod::Month,
            $date === null ? app(PayoutCycle::class)->today() : CarbonImmutable::createFromFormat('!Y-m-d', $date),
            $property->id,
            CommissionService::tryFrom((string) $this->validated('filter.service')),
            $this->validated('filter.search'),
        );
    }
}
