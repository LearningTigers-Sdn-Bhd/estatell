<?php

namespace App\Http\Requests\Rooms;

use App\Enums\HousekeepingStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The ticked blocks of a bulk End now, and the room status for the whole-room blocks among them.
 */
class BulkEndRoomBlocksRequest extends BulkRoomBlocksRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'room_status' => [
                Rule::requiredIf(fn (): bool => $this->property()->roomBlocks()
                    ->whereIn('id', array_map(intval(...), array_filter((array) $this->input('block_ids'), is_numeric(...))))
                    ->whereNull('bed_id')
                    ->exists()),
                'nullable',
                Rule::in([HousekeepingStatus::Clean->value, HousekeepingStatus::Dirty->value]),
            ],
        ];
    }

    public function roomStatus(): ?HousekeepingStatus
    {
        return HousekeepingStatus::tryFrom((string) $this->validated('room_status'));
    }
}
