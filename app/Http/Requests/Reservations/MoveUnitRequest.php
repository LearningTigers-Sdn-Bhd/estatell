<?php

namespace App\Http\Requests\Reservations;

use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The Move room form: the first night, the room or bed to move to and a reason. `MoveUnit` needs the reason unless the
 * first night has no room or bed yet.
 */
class MoveUnitRequest extends MoveUnitPreviewRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from_night' => ['required', 'date_format:Y-m-d'],
            'room_id' => ['nullable', 'integer', 'required_without:bed_id'],
            'bed_id' => ['nullable', 'integer', 'required_without:room_id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The picked room or bed of this property, or null when it is not one of its own.
     */
    public function target(Property $property): Room|Bed|null
    {
        if ($this->filled('bed_id')) {
            return Bed::query()->whereHas('room', fn ($rooms) => $rooms->where('property_id', $property->id))->find($this->integer('bed_id'));
        }

        return Room::query()->where('property_id', $property->id)->find($this->integer('room_id'));
    }
}
