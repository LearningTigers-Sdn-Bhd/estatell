<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Add a partner and its one login with a temp password. The prefix must be free in the property code counters,
 * which also covers ES and every other partner.
 */
class AddPartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code_prefix' => Str::upper(trim((string) $this->input('code_prefix'))),
            'email' => Str::lower(trim((string) $this->input('email'))),
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
            'partner_name' => ['required', 'string', 'max:255'],
            'code_prefix' => [
                'required',
                'string',
                'regex:/^[A-Z]{2}$/',
                'not_in:ES',
                Rule::unique('property_code_counters', 'prefix'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (User::query()->whereRaw('lower(email) = ?', [$value])->exists()) {
                        $fail(__('The email has already been taken.'));
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code_prefix.regex' => __('The prefix must be two capital letters.'),
            'code_prefix.not_in' => __('ES is reserved for direct properties.'),
            'code_prefix.unique' => __('This prefix is already taken.'),
        ];
    }
}
