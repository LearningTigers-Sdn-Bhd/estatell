<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\OnboardingStep;
use App\Enums\SellUnit;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\RoomType;
use App\Services\Rooms\RoomNumberRows;
use App\Services\Rooms\RoomTypeChangeGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * The rooms step of onboarding: one numbers field per group row, the room type of each number, and Beds per room for
 * the bed-sold types. The numbers are parsed again here; the preview is never trusted. A row with no numbers and no
 * saved group is ignored. Saved groups always stay, so a property that has groups always uses groups. A saved room
 * changes type under the guards of RoomTypeChangeGuard: a room with beds needs a bed-sold type, and a room with stays assigned
 * cannot change type.
 */
class SaveRoomsRequest extends OnboardingStepRequest
{
    public const MAX_GROUPS = 50;

    public const MAX_BEDS = 50;

    protected function step(): OnboardingStep
    {
        return OnboardingStep::Rooms;
    }

    /**
     * The `onboarding.only` middleware limits who can reach this route.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $groups = $this->input('groups');

        $this->merge([
            'groups' => is_array($groups) ? array_map(
                fn (mixed $row): mixed => is_array($row) ? [
                    ...$row,
                    'name' => trim((string) ($row['name'] ?? '')),
                    'numbers' => trim((string) ($row['numbers'] ?? '')),
                ] : $row,
                array_values($groups),
            ) : $groups,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->property();

        return [
            'use_groups' => ['required', 'boolean'],
            'groups' => ['required', 'array', 'min:1', 'max:'.self::MAX_GROUPS],
            'groups.*' => ['array'],
            'groups.*.key' => ['required', 'string', 'max:40', 'distinct'],
            'groups.*.id' => [
                'nullable', 'integer', 'distinct',
                Rule::exists('room_groups', 'id')->where('property_id', $property->id),
            ],
            'groups.*.name' => ['nullable', 'string', 'max:100'],
            'groups.*.numbers' => ['nullable', 'string', 'max:2000'],
            'types' => ['present', 'array'],
            'types.*.number' => ['required', 'string', 'max:30'],
            'types.*.room_type_id' => [
                'required', 'integer',
                Rule::exists('room_types', 'id')->where('property_id', $property->id)->whereNull('archived_at'),
            ],
            'beds_per_room' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->checkGroupNames($validator);
                $this->checkNumbers($validator);
            },
        ];
    }

    public function usesGroups(): bool
    {
        return $this->boolean('use_groups') || $this->property()->roomGroups()->exists();
    }

    /**
     * The rows to save, in table order: rows with numbers, and saved groups even when empty. Without groups only the
     * first row counts and has no name.
     *
     * @return list<array{key: string, id: int|null, name: string, numbers: list<string>}>
     */
    public function groups(): array
    {
        $parsed = $this->parsedRows();
        $usesGroups = $this->usesGroups();
        $groups = [];

        foreach ($this->rows() as $index => $row) {
            if (! $usesGroups && $index > 0) {
                break;
            }

            $id = $usesGroups && isset($row['id']) ? (int) $row['id'] : null;

            if ($id === null && $parsed[$index]['numbers'] === []) {
                continue;
            }

            $groups[] = [
                'key' => (string) $row['key'],
                'id' => $id,
                'name' => $usesGroups ? (string) $row['name'] : '',
                'numbers' => $parsed[$index]['numbers'],
            ];
        }

        return $groups;
    }

    /**
     * The room type of each number, keyed by the number in lower case.
     *
     * @return array<string, int>
     */
    public function typeByNumber(): array
    {
        $types = [];

        foreach ((array) $this->validated('types') as $row) {
            $types[mb_strtolower((string) $row['number'])] = (int) $row['room_type_id'];
        }

        return $types;
    }

    /**
     * Beds per room for each bed-sold type that has a room without beds after the save, keyed by room type id.
     *
     * @return array<int, int>
     */
    public function bedsPerRoom(): array
    {
        $counts = [];

        foreach ($this->typesNeedingBeds() as $typeId) {
            $counts[$typeId] = (int) data_get($this->all(), "beds_per_room.{$typeId}");
        }

        return $counts;
    }

    private function property(): Property
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return $property;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return array_values(array_filter((array) $this->input('groups'), is_array(...)));
    }

    /**
     * @return list<array{numbers: list<string>, repeated: list<string>, error: string|null}>
     */
    private function parsedRows(): array
    {
        $usesGroups = $this->usesGroups();

        return app(RoomNumberRows::class)->parse(array_map(
            fn (array $row, int $index): string => ! $usesGroups && $index > 0 ? '' : (string) ($row['numbers'] ?? ''),
            $this->rows(),
            array_keys($this->rows()),
        ));
    }

    /**
     * With groups, a row with numbers needs a name. Names differ ignoring case, from each other and from the saved name
     * of another saved group, so two groups cannot swap names in one save.
     */
    private function checkGroupNames(Validator $validator): void
    {
        if (! $this->usesGroups()) {
            return;
        }

        $parsed = $this->parsedRows();
        $saved = $this->property()->roomGroups()->get(['id', 'name']);
        $seen = [];

        foreach ($this->rows() as $index => $row) {
            $name = (string) $row['name'];
            $id = isset($row['id']) ? (int) $row['id'] : null;

            if ($name === '') {
                if ($id !== null || $parsed[$index]['numbers'] !== [] || $parsed[$index]['error'] !== null) {
                    $validator->errors()->add("groups.{$index}.name", __('Enter a group name.'));
                }

                continue;
            }

            $lower = mb_strtolower($name);
            $takenBySaved = $saved->contains(fn (RoomGroup $group): bool => $group->id !== $id && mb_strtolower($group->name) === $lower);

            if (isset($seen[$lower]) || $takenBySaved) {
                $validator->errors()->add("groups.{$index}.name", __('Another room group already uses this name.'));
            }

            $seen[$lower] = true;
        }
    }

    /**
     * Each row parses, at least one room is listed, every number has a room type, saved rooms pass the type change
     * guards, and every bed-sold type that needs beds has a count.
     */
    private function checkNumbers(Validator $validator): void
    {
        $parsed = $this->parsedRows();
        $types = $this->typeByNumber();

        foreach ($parsed as $index => $row) {
            if ($row['error'] !== null) {
                $validator->errors()->add("groups.{$index}.numbers", $row['error']);
            }
        }

        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if (array_merge(...array_column($parsed, 'numbers') ?: [[]]) === []) {
            $validator->errors()->add('groups.0.numbers', __('Add at least one room number.'));

            return;
        }

        $roomTypes = $this->property()->roomTypes()->active()->get()->keyBy('id');
        $saved = $this->savedRooms();

        foreach ($parsed as $index => $row) {
            foreach ($row['numbers'] as $number) {
                $typeId = $types[mb_strtolower($number)] ?? null;

                if ($typeId === null) {
                    $validator->errors()->add("groups.{$index}.numbers", __('Pick a room type for :number.', ['number' => $number]));

                    continue 2;
                }

                $room = $saved->get(mb_strtolower($number));
                $refusal = $room === null ? null : $this->typeChangeRefusal($room, $roomTypes->get($typeId));

                if ($refusal !== null) {
                    $validator->errors()->add("groups.{$index}.numbers", $refusal);

                    continue 2;
                }
            }
        }

        foreach ($this->typesNeedingBeds() as $typeId) {
            $count = data_get($this->all(), "beds_per_room.{$typeId}");

            if (filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_BEDS]]) === false) {
                $validator->errors()->add("beds_per_room.{$typeId}", __('Enter 1 to :max beds.', ['max' => self::MAX_BEDS]));
            }
        }
    }

    /**
     * The message that stops a saved room taking the room type, or null when it may.
     */
    private function typeChangeRefusal(Room $room, ?RoomType $roomType): ?string
    {
        return $roomType === null ? null : app(RoomTypeChangeGuard::class)->refusal($room, $roomType, nextStep: true);
    }

    /**
     * The active rooms of the property, keyed by number in lower case.
     *
     * @return Collection<string, Room>
     */
    private function savedRooms(): Collection
    {
        return $this->property()->rooms()->active()->get()->keyBy(fn (Room $room): string => mb_strtolower($room->number));
    }

    /**
     * The bed-sold room types that get a room with no active bed: a new room, or a saved room without beds.
     *
     * @return list<int>
     */
    private function typesNeedingBeds(): array
    {
        $bedTypeIds = $this->property()->roomTypes()->active()->where('sell_unit', SellUnit::Bed)->pluck('id')->map(intval(...))->all();

        if ($bedTypeIds === []) {
            return [];
        }

        $types = $this->typeByNumber();
        $withBeds = $this->property()->rooms()->active()->whereHas('beds', fn ($query) => $query->active())
            ->pluck('number')->map(fn (string $number): string => mb_strtolower($number))->flip();
        $needing = [];

        foreach (array_column($this->groups(), 'numbers') as $numbers) {
            foreach ($numbers as $number) {
                $typeId = $types[mb_strtolower($number)] ?? null;

                if ($typeId !== null && in_array($typeId, $bedTypeIds, true) && ! $withBeds->has(mb_strtolower($number))) {
                    $needing[$typeId] = true;
                }
            }
        }

        return array_keys($needing);
    }
}
