<?php

namespace App\Http\Requests\Registration;

use App\Enums\FieldVisibility;
use App\Enums\Locale;
use App\Enums\RegistrationCardField;
use App\Http\Requests\Guests\GuestProfileRules;
use App\Models\Property;
use App\Models\UnitGuest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class SignRegistrationCardRequest extends FormRequest
{
    /** The `can:registration-card.sign` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $identity = CardIdentityRules::normalize($this->input('identity'));

        if ($identity !== null) {
            $this->merge(['identity' => $identity]);
        }
    }

    /**
     * Guest fields follow the card Layout: a Hidden field is not asked, an Optional one may be empty,
     * a Required one must be filled. The checklist is checked by `SignRegistrationCard`.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $visibility = $this->property()->registrationCardFields()->pluck('visibility', 'field')
            ->map(fn ($value): FieldVisibility => $value instanceof FieldVisibility ? $value : FieldVisibility::from($value))
            ->all();
        $rules = ['full_name' => ['required', 'string', 'max:150']];
        $profile = GuestProfileRules::for($this->phoneRegion());
        $fieldsOf = [
            RegistrationCardField::Nationality->value => ['nationality_code'],
            RegistrationCardField::DateOfBirth->value => ['date_of_birth'],
            RegistrationCardField::Gender->value => ['gender'],
            RegistrationCardField::Phone->value => ['phone'],
            RegistrationCardField::Email->value => ['email'],
            RegistrationCardField::Address->value => ['address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code'],
        ];

        foreach ($fieldsOf as $field => $names) {
            $setting = $visibility[$field] ?? FieldVisibility::Hidden;

            foreach ($names as $name) {
                if ($setting === FieldVisibility::Hidden) {
                    continue;
                }

                $rules[$name] = $profile[$name];

                if ($setting === FieldVisibility::Required && in_array($name, ['nationality_code', 'date_of_birth', 'gender', 'phone', 'email', 'address_line1'], true)) {
                    $rules[$name][0] = 'required';
                }
            }
        }

        if (($visibility[RegistrationCardField::VehiclePlate->value] ?? FieldVisibility::Hidden) !== FieldVisibility::Hidden) {
            $rules['vehicle_plate'] = [($visibility[RegistrationCardField::VehiclePlate->value] === FieldVisibility::Required ? 'required' : 'nullable'), 'string', 'max:20'];
        }

        if (($visibility[RegistrationCardField::Remarks->value] ?? FieldVisibility::Hidden) !== FieldVisibility::Hidden) {
            $rules['remarks'] = [($visibility[RegistrationCardField::Remarks->value] === FieldVisibility::Required ? 'required' : 'nullable'), 'string', 'max:500'];
        }

        if (($visibility[RegistrationCardField::MarketingConsent->value] ?? FieldVisibility::Hidden) !== FieldVisibility::Hidden) {
            $rules['marketing_consent'] = ['nullable', 'boolean'];
        }

        $identity = $visibility[RegistrationCardField::IdentityDocument->value] ?? FieldVisibility::Hidden;

        if ($identity !== FieldVisibility::Hidden) {
            $needed = $identity === FieldVisibility::Required;
            $rules = [...$rules, ...CardIdentityRules::for(
                $this->unitGuest()->guest_id,
                $this->input('identity.type'),
                $needed && ! $this->filled('identity.number'),
                $needed && ! $this->filled('identity_document_id'),
            )];
        }

        return [
            ...$rules,
            'locale' => ['required', Rule::enum(Locale::class)],
            'ticks' => ['nullable', 'array', 'max:20'],
            'ticks.*' => ['integer', 'min:0', 'max:19'],
            'signer_name' => ['required', 'string', 'max:150'],
            'signature' => ['required', 'string', 'max:1500000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function actionInput(): array
    {
        $validated = $this->validated();

        return [
            'profile' => GuestProfileRules::attributes($validated, $this->phoneRegion()),
            'identity' => CardIdentityRules::newDocument($validated),
            'identity_document_id' => isset($validated['identity_document_id']) ? (int) $validated['identity_document_id'] : null,
            'vehicle_plate' => $validated['vehicle_plate'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
            'marketing_consent' => (bool) ($validated['marketing_consent'] ?? false),
            'locale' => Locale::from($validated['locale']),
            'ticks' => array_map('intval', $validated['ticks'] ?? []),
            'signature' => $validated['signature'],
            'signer_name' => $validated['signer_name'],
        ];
    }

    private function property(): Property
    {
        $property = $this->route('property');

        return $property instanceof Property ? $property : throw new LogicException('The property route binding is missing.');
    }

    private function unitGuest(): UnitGuest
    {
        $unitGuest = $this->route('unitGuest');

        return $unitGuest instanceof UnitGuest ? $unitGuest : throw new LogicException('The unit guest route binding is missing.');
    }

    private function phoneRegion(): string
    {
        return $this->property()->country_code ?? 'MY';
    }
}
