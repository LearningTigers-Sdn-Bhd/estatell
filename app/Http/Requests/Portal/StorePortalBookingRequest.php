<?php

namespace App\Http\Requests\Portal;

use App\Data\Portal\NewPortalBookingData;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;

/**
 * The booking of the portal book page: the search plus the plan and lead guest of each line, the optional group name
 * and company reference, and the total the page showed. A missing guest name is left to `CreatePortalReservation`,
 * which words it for the line.
 */
class StorePortalBookingRequest extends PortalOffersRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'lines.*.rate_plan_id' => ['required', 'integer'],
            'lines.*.guest_name' => ['nullable', 'string', 'max:150'],
            'lines.*.guest_phone' => ['nullable', 'string', 'max:30', (new Phone)->country($this->phoneRegion())->international()],
            'group_name' => ['nullable', 'string', 'max:150'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'expected_total' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'lines.*.rate_plan_id.required' => __('Choose a rate for every room.'),
            'lines.*.guest_phone.phone' => __('Enter a valid phone number.'),
        ];
    }

    public function toBookingData(): NewPortalBookingData
    {
        $lines = array_map(function ($line) {
            if (filled($line->guestPhone)) {
                $line->guestPhone = (new PhoneNumber($line->guestPhone, $this->phoneRegion()))->formatE164();
            }

            return $line;
        }, $this->lines());

        return new NewPortalBookingData(
            arrivalDate: $this->arrival(),
            departureDate: $this->departure(),
            lines: $lines,
            groupName: $this->validated('group_name'),
            externalReference: $this->validated('external_reference'),
            expectedTotal: $this->validated('expected_total'),
        );
    }

    private function phoneRegion(): string
    {
        /** @var Property $property */
        $property = $this->route('property');

        return $property->country_code ?? 'MY';
    }
}
