<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The OTA connection settings an admin typed on the Connect form. The adapter decides which keys Channex takes, so only
 * the hotel ID is required here: `hotel_id`, or `hotel_code` for adapters that name it so (see ChannelHotelId). The `can:admin` route middleware authorizes this request.
 */
class TestChannelConnectionRequest extends FormRequest
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
        return [
            'settings' => ['required', 'array'],
            'settings.hotel_id' => ['required_without:settings.hotel_code', 'nullable', 'string', 'max:40'],
            'settings.hotel_code' => ['required_without:settings.hotel_id', 'nullable', 'string', 'max:40'],
            'settings.*' => ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'settings.hotel_id.required_without' => __('Enter the hotel ID.'),
            'settings.hotel_code.required_without' => __('Enter the hotel ID.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->validated('settings');
    }
}
