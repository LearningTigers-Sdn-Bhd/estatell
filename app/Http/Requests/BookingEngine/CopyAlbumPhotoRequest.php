<?php

namespace App\Http\Requests\BookingEngine;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One album photo for one template slot. The route's `can:` middleware checks `booking-website.manage`.
 */
class CopyAlbumPhotoRequest extends FormRequest
{
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
            'photo' => ['required', 'integer'],
        ];
    }

    public function photoId(): int
    {
        return $this->integer('photo');
    }
}
