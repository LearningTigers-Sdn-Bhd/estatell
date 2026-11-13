<?php

namespace App\Http\Requests\GuestRequests;

use App\Enums\GuestRequestSource;
use App\Enums\GuestRequestType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGuestRequestRequest extends FormRequest
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
            'type' => ['required', Rule::enum(GuestRequestType::class)],
            'source' => ['required', Rule::enum(GuestRequestSource::class)->except(GuestRequestSource::Concierge)],
            'details' => ['required', 'string', 'max:1000', 'regex:/\S/'],
            'stay_id' => ['nullable', 'integer', 'required_without:room_id'],
            'room_id' => ['nullable', 'integer', 'required_without:stay_id'],
        ];
    }
}
