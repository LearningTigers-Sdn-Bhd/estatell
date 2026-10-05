<?php

namespace App\Http\Requests\Registration;

use App\Enums\FieldVisibility;
use App\Enums\RegistrationCardField;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRegistrationCardFieldsRequest extends FormRequest
{
    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * One entry per field, only values the field type allows, and every field once in the order list.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['fields' => ['required', 'array:'.implode(',', array_column(RegistrationCardField::cases(), 'value'))]];

        foreach (RegistrationCardField::cases() as $field) {
            $rules["fields.{$field->value}"] = [
                'required',
                Rule::in(array_map(fn (FieldVisibility $visibility): string => $visibility->value, $field->allowedVisibilities())),
            ];
        }

        $rules['order'] = ['required', 'array', 'size:'.count(RegistrationCardField::cases())];
        $rules['order.*'] = ['required', 'distinct', Rule::enum(RegistrationCardField::class)];

        return $rules;
    }

    /**
     * The fields in card order.
     *
     * @return list<RegistrationCardField>
     */
    public function order(): array
    {
        return array_map(
            fn (string $field): RegistrationCardField => RegistrationCardField::from($field),
            array_values($this->validated('order')),
        );
    }

    /**
     * @return array<string, FieldVisibility>
     */
    public function visibilities(): array
    {
        return array_map(
            fn (string $visibility): FieldVisibility => FieldVisibility::from($visibility),
            $this->validated('fields'),
        );
    }
}
