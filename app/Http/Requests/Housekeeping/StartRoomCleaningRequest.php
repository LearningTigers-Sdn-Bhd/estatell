<?php

namespace App\Http\Requests\Housekeeping;

use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use App\Services\Housekeeping\RoomCleanings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Start a cleaning. Front office may name the housekeeper; on a room with no assignee they must. Housekeeping always
 * record their own, so a `housekeeper_id` from them is ignored.
 */
class StartRoomCleaningRequest extends FormRequest
{
    private ?User $housekeeper = null;

    /**
     * `can:housekeeping.update` on the route covers the request.
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
        /** @var Property $property */
        $property = $this->route('property');

        return [
            'housekeeper_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('property_id', $property->id)->whereNull('deactivated_at')],
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Room $room */
            $room = $this->route('room');
            $actor = $this->user();
            $canEdit = $actor->can(PermissionEnum::HousekeepingEditTimes->value);
            $picked = $canEdit && $this->filled('housekeeper_id') ? User::query()->find($this->integer('housekeeper_id')) : null;

            if ($picked !== null && ! $picked->can(PermissionEnum::HousekeepingUpdate->value)) {
                $validator->errors()->add('housekeeper_id', __('Pick a staff member who can update housekeeping.'));

                return;
            }

            $this->housekeeper = app(RoomCleanings::class)->housekeeperFor($actor, $room, $canEdit, $picked);

            if ($this->housekeeper === null) {
                $validator->errors()->add('housekeeper_id', __('Pick who is cleaning room :room.', ['room' => $room->number]));
            }
        }];
    }

    public function housekeeper(): User
    {
        return $this->housekeeper ?? throw new \LogicException('The request was not validated.');
    }
}
