<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Contracts\Validation\ValidationRule;

class CheckOutAsDayUseRequest extends CheckOutUnitRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'start' => ['nullable', 'date_format:H:i,H:i:s'],
            'end' => ['nullable', 'date_format:H:i,H:i:s'],
            'amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    public function start(): ?string
    {
        return $this->filled('start') ? $this->string('start')->toString() : null;
    }

    public function end(): ?string
    {
        return $this->filled('end') ? $this->string('end')->toString() : null;
    }

    public function amount(): ?string
    {
        return $this->filled('amount') ? $this->string('amount')->toString() : null;
    }
}
