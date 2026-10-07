<?php

namespace App\Http\Requests\Property;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePrintHeaderRequest extends FormRequest
{
    /** The header line switches. */
    public const array SWITCHES = ['show_logo', 'show_address', 'show_contact', 'show_registration_number', 'show_tin'];

    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...array_fill_keys(self::SWITCHES, ['required', 'boolean']),
            'tax_ids' => ['present', 'array'],
            'tax_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array{show_logo: bool, show_address: bool, show_contact: bool, show_registration_number: bool, show_tin: bool}
     */
    public function switches(): array
    {
        /** @var array{show_logo: bool, show_address: bool, show_contact: bool, show_registration_number: bool, show_tin: bool} */
        return array_map(fn (string $key): bool => $this->boolean($key), array_combine(self::SWITCHES, self::SWITCHES));
    }

    /**
     * @return list<int>
     */
    public function taxIds(): array
    {
        return array_map(intval(...), array_values($this->validated('tax_ids')));
    }
}
