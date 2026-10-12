<?php

namespace App\Http\Requests\Reservations;

use App\Enums\BoatDirection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BoatSeatsRequest extends FormRequest
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
            'direction' => ['required', Rule::enum(BoatDirection::class)],
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
