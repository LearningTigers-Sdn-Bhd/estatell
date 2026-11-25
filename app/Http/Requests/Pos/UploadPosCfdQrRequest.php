<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class UploadPosCfdQrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'qr' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
        ];
    }
}
