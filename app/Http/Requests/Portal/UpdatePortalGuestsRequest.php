<?php

namespace App\Http\Requests\Portal;

use App\Enums\IdentityDocumentType;
use App\Models\Property;
use App\Services\Guests\IdentityNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;

/**
 * The Edit guests form on a portal booking: name, phone and ID of each guest. The ID number is typed in full to
 * replace the one on file and is saved in its normalized form. Whether the guests may still change is decided by
 * `UpdatePortalReservationGuests`.
 */
class UpdatePortalGuestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
            'guests' => ['required', 'array', 'min:1', 'max:99'],
            'guests.*.guest_id' => ['required', 'integer', 'distinct'],
            'guests.*.full_name' => ['required', 'string', 'max:150'],
            'guests.*.phone' => ['nullable', 'string', 'max:30', (new Phone)->country($this->phoneRegion())->international()],
            'guests.*.id_type' => ['nullable', 'required_with:guests.*.id_number', Rule::enum(IdentityDocumentType::class)],
            'guests.*.id_number' => [
                'nullable', 'required_with:guests.*.id_type', 'string', 'min:4', 'max:40',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'guests.*.full_name.required' => __('Enter the guest\'s name.'),
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
        return [function (Validator $validator): void {
            foreach ($this->input('guests', []) as $index => $guest) {
                if (($guest['id_type'] ?? null) === IdentityDocumentType::MyKad->value && preg_match('/^\d{12}$/', (string) ($guest['id_number'] ?? '')) !== 1) {
                    $validator->errors()->add("guests.{$index}.id_number", __('A MyKad number has 12 digits.'));
                }
            }
        }];
    }

    /**
     * @return list<array{guest_id: int, full_name: string, phone: ?string, id_type: ?string, id_number: ?string}>
     */
    public function guests(): array
    {
        return array_values(array_map(fn (array $guest): array => [
            'guest_id' => (int) $guest['guest_id'],
            'full_name' => trim($guest['full_name']),
            'phone' => filled($guest['phone'] ?? null) ? (new PhoneNumber($guest['phone'], $this->phoneRegion()))->formatE164() : null,
            'id_type' => $guest['id_type'] ?? null,
            'id_number' => $guest['id_number'] ?? null,
        ], $this->validated('guests')));
    }

    private function phoneRegion(): string
    {
        /** @var Property $property */
        $property = $this->route('property');

        return $property->country_code ?? 'MY';
    }
}
