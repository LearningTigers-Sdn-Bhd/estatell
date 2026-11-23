<?php

namespace App\Http\Requests\Facilities;

use App\Data\Facilities\FacilityBookingInputData;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Book facility Sheet's quote. Times may be empty until staff pick them. `booking_id` is the booking being
 * edited. The `can:facilities.book` route middleware authorizes it.
 */
class QuoteFacilityBookingRequest extends FormRequest
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
        $property = $this->route('property');
        $propertyId = $property instanceof Property ? $property->id : null;

        return [
            'event_id' => ['required', 'integer', Rule::exists('events', 'id')->where('property_id', $propertyId)],
            'facility_id' => ['required', 'integer', Rule::exists('facilities', 'id')->where('property_id', $propertyId)],
            'booking_id' => ['nullable', 'integer', Rule::exists('facility_bookings', 'id')->where('property_id', $propertyId)],
            'booking_date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i'],
            'quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'unit_amount' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function toInputData(): FacilityBookingInputData
    {
        return new FacilityBookingInputData(
            facilityId: $this->integer('facility_id'),
            bookingDate: $this->string('booking_date')->toString(),
            startsAt: $this->string('starts_at')->toString(),
            endsAt: $this->string('ends_at')->toString(),
            quantity: $this->integer('quantity'),
            unitAmount: $this->filled('unit_amount') ? $this->string('unit_amount')->toString() : null,
            notes: null,
        );
    }
}
