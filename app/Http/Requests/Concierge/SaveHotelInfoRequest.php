<?php

namespace App\Http\Requests\Concierge;

use App\Enums\ParkingAvailability;
use App\Enums\ParkingPriceUnit;
use App\Enums\ParkingType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'parking_availability' => ['nullable', Rule::enum(ParkingAvailability::class)],
            'parking_type' => ['nullable', Rule::enum(ParkingType::class)],
            'parking_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'parking_price_unit' => ['nullable', Rule::enum(ParkingPriceUnit::class)],
            'parking_spaces' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'parking_height_limit_m' => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'parking_ev_charging' => ['sometimes', 'boolean'],
            'parking_booking_required' => ['sometimes', 'boolean'],
            'transport_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
