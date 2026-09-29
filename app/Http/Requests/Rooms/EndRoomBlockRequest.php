<?php

namespace App\Http\Requests\Rooms;

use App\Enums\HousekeepingStatus;
use App\Models\RoomBlock;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EndRoomBlockRequest extends FormRequest
{
    /**
     * The `can:rooms.block` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A whole-room block asks whether the room is Clean or Dirty. A bed block asks nothing.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var RoomBlock $block */
        $block = $this->route('roomBlock');

        return [
            'room_status' => [
                Rule::requiredIf($block->bed_id === null), 'nullable',
                Rule::in([HousekeepingStatus::Clean->value, HousekeepingStatus::Dirty->value]),
            ],
        ];
    }

    public function roomStatus(): ?HousekeepingStatus
    {
        return HousekeepingStatus::tryFrom((string) $this->validated('room_status'));
    }
}
