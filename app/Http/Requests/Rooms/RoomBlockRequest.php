<?php

namespace App\Http\Requests\Rooms;

use App\Enums\RoomBlockReason;
use App\Models\Property;
use App\Models\RoomBlock;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class RoomBlockRequest extends FormRequest
{
    /**
     * Longest block, in nights. Matches the `room_blocks_dates_check` constraint.
     */
    public const MAX_NIGHTS = 365;

    /**
     * The `can:rooms.block` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $notes = trim((string) $this->input('notes'));

        $this->merge([
            'notes' => $notes === '' ? null : $notes,
            'bed_id' => filled($this->input('bed_id')) ? $this->input('bed_id') : null,
        ]);
    }

    /**
     * On create the room and bed are chosen. On edit they stay the same, and a started block keeps its first night.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');
        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        $current = $this->route('roomBlock');
        $today = app(PropertyToday::class)->date($property);
        $startsOnInput = (string) $this->input('starts_on');
        $startsOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOnInput) === 1
            ? (CarbonImmutable::createFromFormat('!Y-m-d', $startsOnInput) ?: $today)
            : $today;

        $startsOnRules = ['required', 'date_format:Y-m-d'];
        if (! $current instanceof RoomBlock || $current->starts_on->toDateString() > $today->toDateString()) {
            $startsOnRules[] = 'after_or_equal:'.$today->toDateString();
        } else {
            $startsOnRules[] = Rule::in([$current->starts_on->toDateString()]);
        }

        return [
            ...($current instanceof RoomBlock ? [] : [
                'room_id' => ['required', 'integer', Rule::exists('rooms', 'id')->where('property_id', $property->id)->whereNull('archived_at')],
                'bed_id' => ['nullable', 'integer', Rule::exists('beds', 'id')->where('room_id', (int) $this->input('room_id'))->whereNull('archived_at')],
            ]),
            'reason' => ['required', Rule::enum(RoomBlockReason::class)],
            'starts_on' => $startsOnRules,
            'ends_on' => [
                'required', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'after_or_equal:'.$today->toDateString(),
                'before_or_equal:'.$startsOn->addDays(self::MAX_NIGHTS - 1)->toDateString(),
            ],
            'notes' => ['nullable', 'string', 'max:500', Rule::requiredIf($this->input('reason') === RoomBlockReason::Other->value)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'starts_on.in' => __('A block that has started keeps its first night.'),
            'starts_on.after_or_equal' => __('The first night cannot be in the past.'),
            'ends_on.after_or_equal' => __('The last night must be today or later, and not before the first night.'),
            'ends_on.before_or_equal' => __('A block can cover at most :count nights.', ['count' => self::MAX_NIGHTS]),
            'notes.required' => __('Add a note when the reason is Other.'),
            'room_id.exists' => __('Choose an active room.'),
            'bed_id.exists' => __('Choose an active bed in this room.'),
        ];
    }

    /**
     * The block fields the Actions write.
     *
     * @return array{reason: string, starts_on: string, ends_on: string, notes: string|null}
     */
    public function blockAttributes(): array
    {
        /** @var array{reason: string, starts_on: string, ends_on: string, notes: string|null} */
        return $this->safe()->only(['reason', 'starts_on', 'ends_on', 'notes']);
    }
}
