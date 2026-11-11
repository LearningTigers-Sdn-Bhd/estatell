<?php

namespace App\Http\Requests\Portal;

use App\Enums\IdentityDocumentType;
use App\Http\Requests\Guests\GuestProfileRules;
use App\Models\Property;
use App\Services\Guests\IdentityNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A sharer added to one unit of a portal booking: the guest profile (name required) and an optional ID, the same fields
 * as a guest row of the Guests tab. Whether the unit takes another guest is decided by `AddPortalUnitGuest`.
 */
class StorePortalUnitGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('id_number'))) {
            $this->merge(['id_number' => app(IdentityNumber::class)->normalize($this->input('id_number'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...GuestProfileRules::for($this->phoneRegion()),
            'id_type' => ['nullable', 'required_with:id_number', Rule::enum(IdentityDocumentType::class)],
            'id_number' => ['nullable', 'required_with:id_type', 'string', 'min:4', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.required' => __('Enter the guest\'s name.'),
            'phone.phone' => __('Enter a valid phone number.'),
            'id_type.required_with' => __('Choose the kind of ID.'),
            'id_number.required_with' => __('Enter the ID number.'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('id_type') === IdentityDocumentType::MyKad->value && preg_match('/^\d{12}$/', (string) $this->input('id_number')) !== 1) {
                $validator->errors()->add('id_number', __('A MyKad number has 12 digits.'));
            }
        }];
    }

    /**
     * The validated profile fields, the phone number in E.164, with no empty ones.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        return array_filter(
            GuestProfileRules::attributes($this->validated(), $this->phoneRegion()),
            fn (mixed $value): bool => filled($value),
        );
    }

    public function idType(): ?IdentityDocumentType
    {
        return filled($this->validated('id_type')) ? IdentityDocumentType::from($this->validated('id_type')) : null;
    }

    public function idNumber(): ?string
    {
        return $this->validated('id_number');
    }

    private function phoneRegion(): string
    {
        /** @var Property $property */
        $property = $this->route('property');

        return $property->country_code ?? 'MY';
    }
}
