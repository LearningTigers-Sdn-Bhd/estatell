<?php

namespace App\Http\Requests\Boats;

use App\Enums\ChargingUnit;
use App\Enums\TransactionCategory;
use App\Http\Requests\Catalogues\ChargeItemRequest;

/**
 * Validates a boat fare. The charge item rules apply, with the fare's fixed values set here: Transport, per guest.
 * A create takes the code and no `active`, as a new fare starts available. An update takes `active` and no code,
 * because the code is kept after create.
 */
class BoatFareRequest extends ChargeItemRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge([
            'category' => TransactionCategory::Transport->value,
            'charging_unit' => ChargingUnit::PerGuest->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->allRules();

        if ($this->isMethod('POST')) {
            unset($rules['active']);
        } else {
            unset($rules['code']);
        }

        $rules['default_amount'] = ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'];

        return $rules;
    }
}
