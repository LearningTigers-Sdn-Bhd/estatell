<?php

namespace App\Http\Requests\Rooms;

use App\Models\RoomType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class ChangeRoomsTypeRequest extends BulkRoomsRequest
{
    /**
     * The new room type must be active. The other guards are per room, in ChangeRoomsType.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'room_type_id' => ['required', 'integer', Rule::exists(RoomType::class, 'id')
                ->where('property_id', $this->property()->id)
                ->whereNull('archived_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_type_id.exists' => __('Choose an active room type.'),
        ];
    }

    public function roomType(): RoomType
    {
        return RoomType::query()->findOrFail($this->integer('room_type_id'));
    }
}
