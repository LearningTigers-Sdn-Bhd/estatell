<?php

namespace App\Http\Requests\Concierge;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveGuestContactsRequest extends FormRequest
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
        $phone = ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]+$/'];

        return [
            'front_desk_open_24h' => ['required', 'boolean'],
            'front_desk_opens_at' => ['nullable', 'required_if:front_desk_open_24h,false', 'date_format:H:i'],
            'front_desk_closes_at' => ['nullable', 'required_if:front_desk_open_24h,false', 'date_format:H:i'],
            'front_desk_whatsapp' => $phone,
            'after_hours_message' => ['nullable', 'string', 'max:500'],
            'duty_manager_phone' => $phone,
            'emergency_phone' => $phone,
            'emergency_services_number' => $phone,
            'emergency_instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
