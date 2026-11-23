<?php

namespace App\Http\Requests\Events;

use App\Data\Facilities\FacilityBookingInputData;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The New event page's quote of its facility lines. Lines may be half filled: no facility yet, or no times. The
 * `can:events.manage` and `can:facilities.book` route middleware authorize it.
 */
class QuoteEventLinesRequest extends FormRequest
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
            'payer_guest_id' => ['nullable', 'integer', Rule::exists('guests', 'id')->where('property_id', $propertyId)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'status' => ['required', Rule::in([EventStatus::Tentative->value, EventStatus::Confirmed->value])],
            'bookings' => ['required', 'array', 'max:30'],
            'bookings.*.facility_id' => ['nullable', 'integer'],
            'bookings.*.booking_date' => ['required', 'date_format:Y-m-d'],
            'bookings.*.starts_at' => ['nullable', 'date_format:H:i'],
            'bookings.*.ends_at' => ['nullable', 'date_format:H:i'],
            'bookings.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'bookings.*.unit_amount' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * The event the lines would belong to, unsaved.
     */
    public function draftEvent(Property $property): Event
    {
        $event = new Event([
            'payer_guest_id' => $this->filled('payer_guest_id') ? $this->integer('payer_guest_id') : null,
            'starts_on' => $this->string('starts_on')->toString(),
            'ends_on' => $this->string('ends_on')->toString(),
            'status' => EventStatus::from($this->string('status')->toString()),
        ]);
        $event->property_id = $property->id;

        return $event;
    }

    /**
     * @return list<FacilityBookingInputData>
     */
    public function lines(): array
    {
        return array_values(array_map(fn (array $line): FacilityBookingInputData => new FacilityBookingInputData(
            facilityId: (int) ($line['facility_id'] ?? 0),
            bookingDate: (string) $line['booking_date'],
            startsAt: (string) ($line['starts_at'] ?? ''),
            endsAt: (string) ($line['ends_at'] ?? ''),
            quantity: (int) $line['quantity'],
            unitAmount: filled($line['unit_amount'] ?? null) ? (string) $line['unit_amount'] : null,
            notes: null,
        ), (array) $this->validated('bookings')));
    }
}
