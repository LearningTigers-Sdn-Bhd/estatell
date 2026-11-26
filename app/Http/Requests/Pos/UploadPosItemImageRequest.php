<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class UploadPosItemImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The pixel limit keeps a small file from expanding into more memory than the resize can take.
     */
    public const array IMAGE_RULES = ['image', 'mimes:png,jpg,jpeg,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['image' => ['required', ...self::IMAGE_RULES]];
    }
}
