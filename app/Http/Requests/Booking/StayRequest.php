<?php

namespace App\Http\Requests\Booking;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A stay and the party of one room: the dates, the adults, the age of each child and the guest's nationality (optional).
 * The token and reach middleware have already checked the client.
 */
class StayRequest extends FormRequest
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
            'arrival' => ['required', 'date_format:Y-m-d'],
            'departure' => ['required', 'date_format:Y-m-d', 'after:arrival'],
            'adults' => ['required', 'integer', 'min:1', 'max:50'],
            'child_ages' => ['nullable', 'array', 'max:50'],
            'child_ages.*' => ['integer', 'min:0', 'max:17'],
            'nationality' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
        ];
    }

    public function arrival(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('arrival')->toString());
    }

    public function departure(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('departure')->toString());
    }

    /**
     * @return list<int>
     */
    public function childAges(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('child_ages', [])));
    }
}
