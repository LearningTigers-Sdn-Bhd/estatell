<?php

namespace App\Http\Requests\Folio;

use App\Data\Folio\NewChargeData;
use App\Models\Folio;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFolioChargeRequest extends FormRequest
{
    /**
     * The `can:folio.post-charge` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. `PostCharge` checks the unit, the charge, the price and the folio, and refuses
     * with a code. A Unit folio uses its own unit. A Master or Third party folio needs the unit to post for.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Folio $folio */
        $folio = $this->route('folio');

        return [
            'unit_id' => [Rule::requiredIf($folio->reservation_unit_id === null), 'nullable', 'integer'],
            'charge_item_id' => ['required', 'integer'],
            'description' => ['nullable', 'string', 'max:120'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'unit_amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'idempotency_key' => ['required', 'string', 'regex:/^manual:[0-9a-fA-F-]{36}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unit_amount.regex' => __('Enter an amount such as 25.00.'),
            'charge_item_id.required' => __('Choose a charge.'),
        ];
    }

    public function toChargeData(): NewChargeData
    {
        return new NewChargeData(
            chargeItemId: $this->integer('charge_item_id'),
            transactionCodeId: null,
            description: $this->filled('description') ? $this->string('description')->toString() : null,
            quantity: $this->integer('quantity'),
            unitAmount: $this->string('unit_amount')->toString(),
            idempotencyKey: $this->string('idempotency_key')->toString(),
        );
    }
}
