<?php

namespace App\Http\Requests\Reservations;

use App\Http\Requests\Concerns\ValidatesRemarks;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveReservationRemarksRequest extends FormRequest
{
    use ValidatesRemarks;

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
        return $this->remarkRules();
    }
}
