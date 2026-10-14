<?php

namespace App\Http\Requests\Rooms;

use App\Enums\SellUnit;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\RoomType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoomRequest extends FormRequest
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
        $this->merge([
            'number' => trim((string) $this->input('number')),
            'room_group_id' => filled($this->input('room_group_id')) ? $this->input('room_group_id') : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $current = $this->route('room');

        return [
            'number' => [
                'required', 'string', 'max:30',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    $taken = Room::query()
                        ->where('property_id', $property->id)
                        ->active()
                        ->whereRaw('lower(number) = ?', [mb_strtolower((string) $value)])
                        ->when($current instanceof Room ? $current->id : null, fn ($query, int $id) => $query->whereKeyNot($id))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another active room already uses this number.'));
                    }
                },
            ],
            'room_type_id' => [
                'required', 'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    $keepsType = $current instanceof Room && $current->room_type_id === (int) $value;

                    $valid = $keepsType || RoomType::query()
                        ->where('property_id', $property->id)
                        ->active()
                        ->whereKey($value)
                        ->exists();

                    if (! $valid) {
                        $fail(__('Choose an active room type.'));

                        return;
                    }

                    $losesBeds = $current instanceof Room
                        && ! $keepsType
                        && $current->beds()->exists()
                        && RoomType::query()->whereKey($value)->where('sell_unit', SellUnit::Room->value)->exists();

                    if ($losesBeds) {
                        $fail(__('This room has beds. Choose a room type that sells beds.'));
                    }
                },
            ],
            'room_group_id' => ['nullable', 'integer', Rule::exists(RoomGroup::class, 'id')->where('property_id', $property->id)],
        ];
    }
}
