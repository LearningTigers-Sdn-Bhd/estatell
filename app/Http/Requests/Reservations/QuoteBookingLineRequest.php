<?php

namespace App\Http\Requests\Reservations;

use App\Data\Reservations\NewAddOnData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class QuoteBookingLineRequest extends FormRequest
{
    /**
     * The `can:reservations.manage` route middleware authorizes this request.
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
            'room_type_id' => ['required', 'integer'],
            'rate_plan_id' => ['required', 'integer'],
            'arrival_date' => ['required', 'date_format:Y-m-d'],
            'departure_date' => ['required', 'date_format:Y-m-d'],
            'adults' => ['required', 'integer', 'min:1', 'max:99'],
            'child_ages' => ['nullable', 'array', 'max:20'],
            'child_ages.*' => ['integer', 'between:0,17'],
            'beds' => ['nullable', 'integer', 'min:1', 'max:99'],
            'override_total' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'add_ons' => ['nullable', 'array', 'max:20'],
            'add_ons.*.charge_item_id' => ['required', 'integer'],
            'add_ons.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'add_ons.*.unit_amount' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'add_ons.*.service_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return list<NewAddOnData>
     */
    public function addOns(): array
    {
        return array_map(fn (array $addOn): NewAddOnData => new NewAddOnData(
            chargeItemId: (int) $addOn['charge_item_id'],
            quantity: isset($addOn['quantity']) ? (int) $addOn['quantity'] : null,
            unitAmount: $addOn['unit_amount'] ?? null,
            serviceDate: isset($addOn['service_date']) ? CarbonImmutable::parse($addOn['service_date']) : null,
        ), $this->validated('add_ons') ?? []);
    }
}
