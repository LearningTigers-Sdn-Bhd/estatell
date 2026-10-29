<?php

namespace App\Http\Requests\Rooms;

use App\Models\Room;
use App\Models\RoomType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * `changes`: a list of `{ room_type_id, room_ids }`. The List tab sends one change, the Map tab one per painted type.
 */
class ChangeRoomsTypeRequest extends BulkRoomsRequest
{
    /**
     * Each new room type must be active. A room may appear in one change only, and all changes together take at
     * most 200 rooms. The other guards are per room, in ChangeRoomsType.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'changes' => ['required', 'array', 'min:1', function (string $attribute, mixed $value, Closure $fail): void {
                $ids = $this->roomIdsIn($value);

                if (count($ids) > self::MAX_ROOMS) {
                    $fail(__('Change at most :count rooms at once.', ['count' => self::MAX_ROOMS]));
                } elseif (count($ids) !== count(array_unique($ids))) {
                    $fail(__('A room can have only one new room type.'));
                }
            }],
            'changes.*.room_type_id' => ['required', 'integer', Rule::exists(RoomType::class, 'id')
                ->where('property_id', $this->property()->id)
                ->whereNull('archived_at')],
            'changes.*.room_ids' => ['required', 'array', 'min:1'],
            'changes.*.room_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'changes.*.room_type_id.exists' => __('Choose an active room type.'),
        ];
    }

    /**
     * Each change as its room type and its rooms in room order. An id that is not a room of the property is a
     * tampered request, so it is a 404 and nothing changes.
     *
     * @return list<array{roomType: RoomType, rooms: Collection<int, Room>}>
     */
    public function changes(): array
    {
        /** @var list<array{room_type_id: int|string, room_ids: list<int|string>}> $changes */
        $changes = $this->validated('changes');
        $ids = $this->roomIdsIn($changes);
        $rooms = $this->property()->rooms()->whereIn('id', $ids)->ordered()->get();

        abort_if($rooms->count() !== count($ids), 404);

        $roomTypes = $this->property()->roomTypes()->whereIn('id', array_column($changes, 'room_type_id'))->get()->keyBy('id');

        return array_map(fn (array $change): array => [
            'roomType' => $roomTypes->get((int) $change['room_type_id']) ?? abort(404),
            'rooms' => $rooms->whereIn('id', array_map('intval', $change['room_ids']))->values(),
        ], $changes);
    }

    /**
     * Every room id of every change, as integers, before validation has checked the shape.
     *
     * @return list<int>
     */
    private function roomIdsIn(mixed $changes): array
    {
        if (! is_array($changes)) {
            return [];
        }

        $ids = [];

        foreach ($changes as $change) {
            foreach (is_array($change) && is_array($change['room_ids'] ?? null) ? $change['room_ids'] : [] as $id) {
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $ids[] = (int) $id;
                }
            }
        }

        return $ids;
    }
}
