<?php

namespace App\Http\Requests\Concierge;

use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadHeroRequest extends FormRequest
{
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
            'hero' => ['required', 'file', 'mimetypes:'.implode(',', Property::PHOTO_MIME_TYPES), 'extensions:'.implode(',', Property::PHOTO_EXTENSIONS), 'max:'.Property::PHOTO_MAX_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hero.mimetypes' => __('The photo must be a JPEG, PNG or WebP image.'),
            'hero.extensions' => __('The photo must be a JPEG, PNG or WebP image.'),
            'hero.max' => __('The photo must be 5 MB or smaller.'),
        ];
    }
}
