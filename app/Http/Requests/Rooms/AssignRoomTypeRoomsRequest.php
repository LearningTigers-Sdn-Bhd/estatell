<?php

namespace App\Http\Requests\Rooms;

use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignRoomTypeRoomsRequest extends FormRequest
{
    /**
     * The `can:rooms.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rows = collect($this->input('rooms', []))
            ->map(fn (mixed $row): mixed => is_array($row)
                ? [...$row, 'room_group_id' => filled($row['room_group_id'] ?? null) ? $row['room_group_id'] : null]
                : $row)
            ->all();

        $this->merge(['rooms' => $rows]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');

        return [
            'rooms' => ['present', 'array'],
            'rooms.*.room_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('rooms', 'id')->where('property_id', $property->id)->whereNull('archived_at'),
            ],
            'rooms.*.room_group_id' => ['nullable', 'integer', Rule::exists('room_groups', 'id')->where('property_id', $property->id)],
        ];
    }

    /**
     * @return list<array{room_id: int, room_group_id: int|null}>
     */
    public function rows(): array
    {
        return array_values(array_map(
            fn (array $row): array => [
                'room_id' => (int) $row['room_id'],
                'room_group_id' => $row['room_group_id'] === null ? null : (int) $row['room_group_id'],
            ],
            $this->validated('rooms'),
        ));
    }
}
