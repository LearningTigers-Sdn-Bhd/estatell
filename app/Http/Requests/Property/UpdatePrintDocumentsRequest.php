<?php

namespace App\Http\Requests\Property;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePrintDocumentsRequest extends FormRequest
{
    /** The document switches. */
    public const array SWITCHES = [
        'show_unit_on_lines', 'nightly_breakdown', 'tax_breakdown', 'show_amount_in_words',
        'show_cancellation_policy', 'show_signature_lines',
    ];

    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The payment details arrive trimmed and an empty text as null (the global middleware).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...array_fill_keys(self::SWITCHES, ['required', 'boolean']),
            'bank_details' => ['nullable', 'string', 'max:500'],
        ];
    }
}
