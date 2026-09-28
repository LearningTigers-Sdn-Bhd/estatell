<?php

namespace App\Http\Requests\Admin;

use App\Concerns\PasswordValidationRules;
use App\Enums\OwnerSetupMethod;
use App\Enums\PropertyType;
use App\Models\User;
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
        ]);
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
