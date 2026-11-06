<?php

namespace App\Http\Requests\Admin;

use App\Enums\BookingEngineMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingEngineModeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(BookingEngineMode::class)],
        ];
    }

    public function mode(): BookingEngineMode
    {
        return BookingEngineMode::from($this->string('mode')->toString());
    }
}
