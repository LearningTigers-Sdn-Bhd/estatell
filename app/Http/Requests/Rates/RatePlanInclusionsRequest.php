<?php

namespace App\Http\Requests\Rates;

use App\Enums\ChargingUnit;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RatePlanInclusionsRequest extends FormRequest
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
        /** @var Property $property */
        $property = $this->route('property');

        return [
            'inclusions' => ['present', 'array', 'max:30'],
            'inclusions.*.id' => ['nullable', 'integer', 'distinct'],
            'inclusions.*.charge_item_id' => ['required', 'integer', Rule::exists('charge_items', 'id')->where('property_id', $property->id)],
            'inclusions.*.meal_period_id' => ['nullable', 'integer', Rule::exists('meal_periods', 'id')->where('property_id', $property->id)],
            'inclusions.*.charging_unit' => ['required', Rule::enum(ChargingUnit::class)],
            'inclusions.*.quantity' => ['required', 'integer', 'between:1,99'],
            'inclusions.*.allocated_amount' => ['required', 'decimal:0,2', 'numeric', 'min:0', 'max:9999999999.99'],
            'inclusions.*.starts_on' => ['required', Rule::enum(InclusionStart::class)],
            'inclusions.*.ends_on' => ['required', Rule::enum(InclusionEnd::class)],
        ];
    }

    /**
     * @return list<array{id: int|null, charge_item_id: int, meal_period_id: int|null, charging_unit: string, quantity: int, allocated_amount: string, starts_on: string, ends_on: string}>
     */
    public function inclusions(): array
    {
        return array_map(
            fn (array $inclusion): array => [
                'id' => isset($inclusion['id']) ? (int) $inclusion['id'] : null,
                'charge_item_id' => (int) $inclusion['charge_item_id'],
                'meal_period_id' => isset($inclusion['meal_period_id']) ? (int) $inclusion['meal_period_id'] : null,
                'charging_unit' => $inclusion['charging_unit'],
                'quantity' => (int) $inclusion['quantity'],
                'allocated_amount' => (string) $inclusion['allocated_amount'],
                'starts_on' => $inclusion['starts_on'],
                'ends_on' => $inclusion['ends_on'],
            ],
            array_values($this->validated('inclusions')),
        );
    }
}
