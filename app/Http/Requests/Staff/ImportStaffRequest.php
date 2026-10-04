<?php

namespace App\Http\Requests\Staff;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class ImportStaffRequest extends FormRequest
{
    /**
     * The `can:staff.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', File::types(['csv', 'txt', 'xlsx'])->extensions(['csv', 'xlsx'])->max('1mb')],
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
