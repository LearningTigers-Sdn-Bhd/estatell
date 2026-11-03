<?php

namespace App\Http\Requests\Rooms;

use App\Models\RoomType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UploadRoomTypePhotosRequest extends FormRequest
{
    /**
     * The `can:rooms.manage` route middleware authorizes this request.
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
            'photos' => ['required', 'array', 'min:1', 'max:'.RoomType::PHOTO_LIMIT],
            'photos.*' => ['file', 'mimetypes:'.implode(',', RoomType::PHOTO_MIME_TYPES), 'extensions:'.implode(',', RoomType::PHOTO_EXTENSIONS), 'max:'.RoomType::PHOTO_MAX_KB],
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
