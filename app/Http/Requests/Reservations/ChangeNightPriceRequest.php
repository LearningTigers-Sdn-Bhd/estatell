<?php

namespace App\Http\Requests\Reservations;

use App\Models\ReservationUnit;
use App\Models\UnitNight;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Change price form and its preview: the ticked nights of the unit, one price per night and the reason. The
 * preview takes the same fields with the nights, price and reason optional, so the Dialog opens with no night ticked.
 */
class ChangeNightPriceRequest extends FormRequest
{
    /**
     * The `can:reservations.change-price` route middleware authorizes this request.
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
        $preview = $this->routeIs('reservations.units.night-price.preview');

        return [
            'night_ids' => $preview ? ['nullable', 'array', 'max:400'] : ['required', 'array', 'min:1', 'max:400'],
            'night_ids.*' => ['integer', 'distinct', Rule::exists('unit_nights', 'id')->where('reservation_unit_id', $this->unit()->id)],
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
            'night_ids.required' => __('Tick at least one night.'),
            'night_ids.*.exists' => __('Tick at least one night of this unit.'),
            'price.regex' => __('Enter a price of 0 or more, with up to two decimals.'),
        ];
    }

    /**
     * @return list<UnitNight>
     */
    public function nights(): array
    {
        return $this->unit()->nights()->whereIn('id', $this->nightIds())->orderBy('date')->get()->all();
    }

    /**
     * @return list<int>
     */
    public function nightIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('night_ids', [])));
    }

    public function price(): ?string
    {
        return filled($this->input('price')) ? $this->string('price')->toString() : null;
    }

    private function unit(): ReservationUnit
    {
        return $this->route('unit');
    }
}
