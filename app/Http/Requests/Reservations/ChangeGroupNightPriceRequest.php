<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The group Change price form and its preview: the ticked units, one price per night and the reason. The preview
 * takes the price and the reason as optional, so the Dialog opens before a price is typed.
 */
class ChangeGroupNightPriceRequest extends FormRequest
{
    /**
     * The `can:reservations.change-price` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. `ChangeGroupNightPrice` ignores ids of other reservations.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $preview = $this->routeIs('reservations.night-price.preview');

        return [
            'unit_ids' => ['required', 'array', 'min:1', 'max:200'],
            'unit_ids.*' => ['integer'],
            'price' => [$preview ? 'nullable' : 'required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'reason' => [$preview ? 'nullable' : 'required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unit_ids.required' => __('Tick at least one unit.'),
            'unit_ids.min' => __('Tick at least one unit.'),
            'price.regex' => __('Enter a price of 0 or more, with up to two decimals.'),
        ];
    }

    /**
     * @return list<int>
     */
    public function unitIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('unit_ids')));
    }

    public function price(): ?string
    {
        return filled($this->input('price')) ? $this->string('price')->toString() : null;
    }
}
