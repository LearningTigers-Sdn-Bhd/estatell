<?php

namespace App\Http\Requests\Catalogues;

use App\Models\Property;
use App\Models\PropertyBankAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

class BankAccountRequest extends FormRequest
{
    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $swiftCode = Str::of((string) $this->input('swift_code'))->replaceMatches('/\s+/', '')->upper()->toString();

        $this->merge([
            'bank_name' => trim((string) $this->input('bank_name')),
            'account_name' => trim((string) $this->input('account_name')),
            'account_number' => trim((string) $this->input('account_number')),
            'swift_code' => $swiftCode === '' ? null : $swiftCode,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');
        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        $bankAccount = $this->route('bankAccount');

        return [
            'bank_name' => ['required', 'string', 'max:100'],
            'account_name' => ['required', 'string', 'max:150'],
            'account_number' => [
                'required', 'string', 'max:34',
                Rule::unique('property_bank_accounts', 'account_number')
                    ->where('property_id', $property->id)
                    ->ignore($bankAccount instanceof PropertyBankAccount ? $bankAccount->id : null),
            ],
            'swift_code' => ['nullable', 'string', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/'],
            'active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'account_number.unique' => __('Another bank account already uses this number.'),
            'swift_code.regex' => __('Enter a SWIFT code of 8 or 11 letters and numbers, like MBBEMYKL.'),
        ];
    }

    /** @return array{bank_name: string, account_name: string, account_number: string, swift_code: ?string, active: bool} */
    public function bankAccount(): array
    {
        return [
            'bank_name' => $this->string('bank_name')->toString(),
            'account_name' => $this->string('account_name')->toString(),
            'account_number' => $this->string('account_number')->toString(),
            'swift_code' => $this->validated('swift_code'),
            'active' => $this->boolean('active'),
        ];
    }
}
