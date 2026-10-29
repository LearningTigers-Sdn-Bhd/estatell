<?php

namespace App\Http\Requests\Rooms;

use App\Models\Property;
use App\Models\Room;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * The rooms ticked on Settings > Rooms for a bulk action.
 */
class BulkRoomsRequest extends FormRequest
{
    public const MAX_ROOMS = 200;

    /**
     * The `can:rooms.manage` route middleware authorizes this request.
     */
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
            'room_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROOMS],
            'room_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * The ticked rooms in room order, archived ones included so the action can list them. An id that is not a room
     * of the property is a tampered request, so it is a 404.
     *
     * @return Collection<int, Room>
     */
    public function rooms(): Collection
    {
        $ids = array_map('intval', $this->validated('room_ids'));
        $rooms = $this->property()->rooms()->whereIn('id', $ids)->ordered()->get();

        abort_if($rooms->count() !== count($ids), 404);

        return $rooms;
    }

    protected function property(): Property
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return $property;
    }
}
