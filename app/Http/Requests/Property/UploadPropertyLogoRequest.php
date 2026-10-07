<?php

namespace App\Http\Requests\Property;

use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadPropertyLogoRequest extends FormRequest
{
    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * PNG or JPEG up to 1 MB. SVG is refused because dompdf draws it poorly.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', 'mimetypes:'.implode(',', Property::LOGO_MIME_TYPES), 'max:1024'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.mimetypes' => __('The logo must be a PNG or JPEG image.'),
        ];
    }
}
