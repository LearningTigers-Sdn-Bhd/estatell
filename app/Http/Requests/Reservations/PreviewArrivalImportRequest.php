<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class PreviewArrivalImportRequest extends FormRequest
{
    /**
     * The `can:reservations.manage` and `can:folio.manual` route middleware authorize this request.
     */
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
            'file' => ['required', File::types(['csv', 'txt', 'xlsx'])->extensions(['csv', 'xlsx'])->max('2mb')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => __('Upload a CSV or XLSX file.'),
            'file.extensions' => __('Upload a CSV or XLSX file.'),
        ];
    }
}
