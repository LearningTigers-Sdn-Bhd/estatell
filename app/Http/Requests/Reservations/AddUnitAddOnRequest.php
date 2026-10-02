<?php

namespace App\Http\Requests\Reservations;

use App\Data\Reservations\NewAddOnData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddUnitAddOnRequest extends FormRequest
{
    /**
     * The `can:reservations.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. `AddUnitAddOn` checks the stay, the quantity and the price, and refuses with a code.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'charge_item_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer'],
            'unit_amount' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'service_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unit_amount.regex' => __('Enter an amount such as 25.00.'),
        ];
    }

    public function toAddOnData(): NewAddOnData
    {
        return new NewAddOnData(
            chargeItemId: $this->integer('charge_item_id'),
            quantity: $this->filled('quantity') ? $this->integer('quantity') : null,
            unitAmount: $this->filled('unit_amount') ? $this->string('unit_amount')->toString() : null,
            serviceDate: $this->filled('service_date') ? $this->date('service_date', 'Y-m-d')->toImmutable() : null,
        );
    }
}
