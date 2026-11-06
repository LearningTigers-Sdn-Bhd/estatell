<?php

namespace App\Http\Requests\BookingEngine;

use App\Models\PropertyBookingSite;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One image for one template slot. The route's `can:` middleware checks `booking-website.manage`.
 */
class UploadSiteImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * JPEG, PNG or WebP up to 5 MB.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimetypes:'.implode(',', PropertyBookingSite::IMAGE_MIME_TYPES), 'extensions:'.implode(',', PropertyBookingSite::IMAGE_EXTENSIONS), 'max:'.PropertyBookingSite::IMAGE_MAX_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.extensions' => __('The image must be a JPEG, PNG or WebP file.'),
            'image.mimetypes' => __('The image must be a JPEG, PNG or WebP file.'),
            'image.max' => __('The image must be 5 MB or smaller.'),
            'image.uploaded' => __('The image could not be uploaded. It must be 5 MB or smaller.'),
        ];
    }
}
