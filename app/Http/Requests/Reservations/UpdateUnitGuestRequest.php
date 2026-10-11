<?php

namespace App\Http\Requests\Reservations;

use App\Http\Requests\Guests\GuestProfileRules;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUnitGuestRequest extends FormRequest
{
    /**
     * The `can:reservations.manage` and `can:guests.manage` route middleware authorize this request.
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
        return GuestProfileRules::for($this->phoneRegion());
    }

    /**
     * The profile fields for the guest, with the phone number in E.164.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        return GuestProfileRules::attributes($this->validated(), $this->phoneRegion());
    }

    private function phoneRegion(): string
    {
        $property = $this->route('property');

        return $property instanceof Property ? ($property->country_code ?? 'MY') : 'MY';
    }
}
