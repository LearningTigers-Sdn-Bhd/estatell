<?php

namespace App\Http\Requests\Concierge;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveHotelInfoRequest extends FormRequest
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
        $minutes = ['nullable', 'integer', 'min:0', 'max:1440'];
        $km = ['nullable', 'integer', 'min:0', 'max:9999'];

        return [
            'about' => ['nullable', 'string', 'max:3000'],
            'arrival_instructions' => ['nullable', 'string', 'max:1000'],
            'departure_instructions' => ['nullable', 'string', 'max:1000'],
            'directions' => ['nullable', 'string', 'max:2000'],
            'airport_distance_km' => $km,
            'airport_travel_minutes' => $minutes,
            'station_distance_km' => $km,
            'station_travel_minutes' => $minutes,
            'city_centre_distance_km' => $km,
            'city_centre_travel_minutes' => $minutes,
            'nearest_transit_stop' => ['nullable', 'string', 'max:200'],
            'airport_transfer_offered' => ['required', 'boolean'],
            'airport_transfer_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'airport_transfer_lead_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'pickup_point' => ['nullable', 'string', 'max:200'],
            'shuttle_schedule' => ['nullable', 'string', 'max:200'],
            'parking_notes' => ['nullable', 'string', 'max:500'],
            'transport_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
