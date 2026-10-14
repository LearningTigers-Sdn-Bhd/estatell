<?php

namespace App\Http\Requests\Property;

use App\Data\Property\PrintHeaderData;
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

    public function toPrintHeaderData(): PrintHeaderData
    {
        return new PrintHeaderData(
            showLogo: $this->boolean('show_logo'),
            showAddress: $this->boolean('show_address'),
            showContact: $this->boolean('show_contact'),
            showRegistrationNumber: $this->boolean('show_registration_number'),
            showTin: $this->boolean('show_tin'),
        );
    }

    /**
     * @return list<int>
     */
    public function taxIds(): array
    {
        return array_map(intval(...), array_values($this->validated('tax_ids')));
    }
}
