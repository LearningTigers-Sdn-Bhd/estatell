<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The Late check-out form: the choices of a policy fee form and the new departure time.
 */
class LateCheckOutRequest extends UnitPolicyFeeRequest
{
    /**
     * The `can:front-desk.check-out` route middleware authorizes this request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'new_time' => ['required', 'date_format:H:i']];
    }
}
