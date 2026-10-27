<?php

namespace App\Http\Requests\Reservations;

use App\Models\Reservation;
use App\Models\ReservationUnit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveUnitGuestRequest extends FormRequest
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
        $reservation = $this->route('reservation');
        $unit = $this->route('unit');

        return [
            'target_unit_id' => [
                'required',
                'integer',
                Rule::exists('reservation_units', 'id')->where('reservation_id', $reservation instanceof Reservation ? $reservation->id : 0),
                Rule::notIn([$unit instanceof ReservationUnit ? $unit->id : 0]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_unit_id.required' => __('Choose the unit to move this guest to.'),
            'target_unit_id.exists' => __('Choose a unit of this reservation.'),
            'target_unit_id.not_in' => __('The guest is already in this unit.'),
        ];
    }
}
