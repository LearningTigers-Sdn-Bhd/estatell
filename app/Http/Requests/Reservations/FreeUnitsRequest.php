<?php

namespace App\Http\Requests\Reservations;

use App\Enums\StayType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FreeUnitsRequest extends FormRequest
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
            'room_type_id' => ['required', 'integer'],
            'arrival_date' => ['required', 'date_format:Y-m-d'],
            'stay_type' => ['nullable', Rule::enum(StayType::class)],
            'departure_date' => ['required_unless:stay_type,day_use', 'nullable', 'date_format:Y-m-d'],
            'start_time' => ['required_if:stay_type,day_use', 'nullable', 'date_format:H:i'],
            'hours' => ['required_if:stay_type,day_use', 'nullable', 'integer', 'between:1,24'],
        ];
    }

    public function isDayUse(): bool
    {
        return $this->input('stay_type') === StayType::DayUse->value;
    }
}
