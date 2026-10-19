<?php

namespace App\Http\Requests\Portal;

use App\Data\Portal\NewPortalBookingData;
use App\Data\Portal\NewPortalGuestData;
use App\Enums\IdentityDocumentType;
use App\Models\Property;
use App\Services\Guests\IdentityNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;

/**
 * The booking of the portal book page: the lines with their plan and room type, one lead guest for each unit (each
 * room, then each bed, in line order) with an optional ID, the optional group name and company reference, and the
 * total the page showed. A missing guest name is left to `CreatePortalReservation`, which words it for the guest.
 */
class StorePortalBookingRequest extends PortalOffersRequest
{
    protected function prepareForValidation(): void
    {
        $guests = $this->input('guests');

        if (! is_array($guests)) {
            return;
        }

        $this->merge(['guests' => array_map(function (mixed $guest): mixed {
            if (is_array($guest) && is_string($guest['id_number'] ?? null)) {
                $guest['id_number'] = app(IdentityNumber::class)->normalize($guest['id_number']);
            }

            return $guest;
        }, $guests)]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'lines.*.rate_plan_id' => ['required', 'integer'],
            'lines.*.room_type_id' => ['required', 'integer'],
            'guests' => ['required', 'array', 'min:1', 'max:99'],
            'guests.*.full_name' => ['nullable', 'string', 'max:150'],
            'guests.*.phone' => ['nullable', 'string', 'max:30', (new Phone)->country($this->phoneRegion())->international()],
            'guests.*.id_type' => ['nullable', 'required_with:guests.*.id_number', Rule::enum(IdentityDocumentType::class)],
            'guests.*.id_number' => ['nullable', 'required_with:guests.*.id_type', 'string', 'min:4', 'max:40'],
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
            'lines.*.rate_plan_id.required' => __('Choose a rate for every line.'),
            'lines.*.room_type_id.required' => __('Choose a room type for every line.'),
            'guests.max' => __('A booking can have up to 99 rooms and beds.'),
            'guests.*.phone.phone' => __('Enter a valid phone number.'),
            'guests.*.id_type.required_with' => __('Choose the kind of ID.'),
            'guests.*.id_number.required_with' => __('Enter the ID number.'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            foreach ($this->input('guests', []) as $index => $guest) {
                if (is_array($guest) && ($guest['id_type'] ?? null) === IdentityDocumentType::MyKad->value && preg_match('/^\d{12}$/', (string) ($guest['id_number'] ?? '')) !== 1) {
                    $validator->errors()->add("guests.{$index}.id_number", __('A MyKad number has 12 digits.'));
                }
            }
        }];
    }

    public function toBookingData(): NewPortalBookingData
    {
        return new NewPortalBookingData(
            arrivalDate: $this->arrival(),
            departureDate: $this->departure(),
            lines: $this->lines(),
            guests: array_values(array_map(fn (array $guest): NewPortalGuestData => new NewPortalGuestData(
                fullName: $guest['full_name'] ?? null,
                phone: filled($guest['phone'] ?? null) ? (new PhoneNumber($guest['phone'], $this->phoneRegion()))->formatE164() : null,
                idType: filled($guest['id_type'] ?? null) ? IdentityDocumentType::from($guest['id_type']) : null,
                idNumber: $guest['id_number'] ?? null,
            ), $this->validated('guests'))),
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
