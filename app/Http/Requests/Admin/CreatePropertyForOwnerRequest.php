<?php

namespace App\Http\Requests\Admin;

use App\Concerns\PasswordValidationRules;
use App\Data\Onboarding\NewOwnerPropertyData;
use App\Enums\OwnerSetupMethod;
use App\Enums\PropertyType;
use App\Models\User;
use App\Services\Guests\CountryOptions;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreatePropertyForOwnerRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'owner_email' => Str::lower(trim((string) $this->input('owner_email'))),
            'verify_owner_email' => $this->boolean('verify_owner_email'),
            'country_code' => $this->filled('country_code') ? mb_strtoupper(trim((string) $this->input('country_code'))) : 'MY',
        ]);
    }

    /**
     * Read from the validated input: `password` is excluded unless the setup method is InitialPassword.
     */
    public function toNewOwnerPropertyData(): NewOwnerPropertyData
    {
        $validated = $this->validated();

        return new NewOwnerPropertyData(
            propertyName: $this->string('property_name')->toString(),
            type: PropertyType::from($this->string('type')->toString()),
            ownerName: $this->string('owner_name')->toString(),
            ownerEmail: $this->string('owner_email')->toString(),
            setupMethod: OwnerSetupMethod::from($this->string('setup_method')->toString()),
            password: isset($validated['password']) ? (string) $validated['password'] : null,
            verifyOwnerEmail: (bool) ($validated['verify_owner_email'] ?? false),
            countryCode: (string) $validated['country_code'],
        );
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'property_name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(PropertyType::class)],
            'country_code' => ['required', 'string', Rule::in(app(CountryOptions::class)->codes())],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (User::query()->whereRaw('lower(email) = ?', [$value])->exists()) {
                        $fail(__('The owner email has already been taken.'));
                    }
                },
            ],
            'setup_method' => ['required', Rule::enum(OwnerSetupMethod::class)],
            'verify_owner_email' => [
                'required',
                'boolean',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value && $this->input('setup_method') !== OwnerSetupMethod::InitialPassword->value) {
                        $fail(__('The owner email can only be marked as verified when using an initial password.'));
                    }
                },
            ],
            'password' => [
                'exclude_unless:setup_method,'.OwnerSetupMethod::InitialPassword->value,
                ...$this->passwordRules(),
            ],
        ];
    }
}
