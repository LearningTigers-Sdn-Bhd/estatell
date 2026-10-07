<?php

namespace App\Http\Requests\Property;

use App\Enums\PdfFont;
use App\Enums\PdfFontSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePrintSettingsRequest extends FormRequest
{
    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The footer text arrives trimmed and an empty text as null (the global middleware), which the CHECK expects.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'font' => ['required', Rule::enum(PdfFont::class)],
            'font_size' => ['required', Rule::enum(PdfFontSize::class)],
            'footer_text' => ['nullable', 'string', 'max:500'],
        ];
    }
}
