<?php

namespace App\Http\Requests\Facilities;

use App\Data\Facilities\FacilityBookingInputData;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Book facility and Edit booking. The `can:facilities.book` route middleware authorizes it. A new booking names its
 * event and facility; an edit keeps both. `BookFacility` and `UpdateFacilityBooking` run the state checks.
 * See docs/33-facilities/04-facility-bookings.md#book-facility-sheet.
 */
class FacilityBookingRequest extends FormRequest
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
        $isNew = $this->route('facilityBooking') === null;

        return [
            'event_id' => [Rule::excludeIf(! $isNew), 'required', 'integer', Rule::exists('events', 'id')->where('property_id', $propertyId)],
            'facility_id' => [Rule::excludeIf(! $isNew), 'required', 'integer', Rule::exists('facilities', 'id')->where('property_id', $propertyId)],
            'booking_date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'unit_amount' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'facility_id.required' => __('Choose a facility.'),
            'starts_at.required' => __('Enter the start time, or choose a session.'),
            'ends_at.after' => __('The end time must be after the start time. A booking cannot cross midnight.'),
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
            notes: $this->filled('notes') ? $this->string('notes')->toString() : null,
        );
    }
}
