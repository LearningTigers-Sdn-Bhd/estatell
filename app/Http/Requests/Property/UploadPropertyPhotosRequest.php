<?php

namespace App\Http\Requests\Property;

use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UploadPropertyPhotosRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * JPEG, PNG or WebP up to 5 MB each.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1', 'max:'.Property::PHOTO_LIMIT],
            'photos.*' => ['file', 'mimetypes:'.implode(',', Property::PHOTO_MIME_TYPES), 'extensions:'.implode(',', Property::PHOTO_EXTENSIONS), 'max:'.Property::PHOTO_MAX_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photos.*.extensions' => __('Photos must be JPEG, PNG or WebP images.'),
            'photos.*.mimetypes' => __('Photos must be JPEG, PNG or WebP images.'),
            'photos.*.max' => __('Each photo must be 5 MB or smaller.'),
            'photos.*.uploaded' => __('A photo could not be uploaded. Each photo must be 5 MB or smaller.'),
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function photos(): array
    {
        return array_values($this->file('photos'));
    }
}
