<?php

namespace App\Http\Requests\Boats;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The ticked trips of the Boats board: Move to and Mark arrived or left. A move names a stored sailing (`boat_sailing_id`)
 * or a departure and a date (a date with no stored sailing). A trip of another property is a 404 in the controller. The
 * route checks `boats` and `reservations.manage`.
 */
class BoatBoardTripsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isMove = $this->routeIs('boats.trips.move');

        return [
            'trip_ids' => ['required', 'array', 'min:1', 'max:200'],
            'trip_ids.*' => ['integer', 'distinct'],
            'ticked' => ['sometimes', 'boolean'],
            'boat_sailing_id' => $isMove ? ['nullable', 'integer', 'required_without:boat_departure_id'] : ['prohibited'],
            'boat_departure_id' => $isMove ? ['nullable', 'integer', 'required_without:boat_sailing_id'] : ['prohibited'],
            'date' => $isMove ? ['nullable', 'required_with:boat_departure_id', 'date_format:Y-m-d'] : ['prohibited'],
        ];
    }

    /** @return list<int> */
    public function tripIds(): array
    {
        return array_values(array_unique(array_map(intval(...), $this->validated('trip_ids'))));
    }
}
