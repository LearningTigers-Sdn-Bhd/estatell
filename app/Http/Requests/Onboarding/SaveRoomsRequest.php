<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\OnboardingStep;
use App\Enums\SellUnit;
use App\Models\Property;
use App\Services\Rooms\RoomNumberAvailability;
use App\Services\Rooms\RoomNumberGenerator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * The rooms table of onboarding: optional room groups and one range row per run of room numbers. A range row with no
 * prefix, from and to is ignored. Saved groups always stay, so a property that has groups always uses groups. The
 * limits follow GenerateRoomsRequest.
 */
class SaveRoomsRequest extends OnboardingStepRequest
{
    public const MAX_RANGES = 50;

    public const MAX_GROUPS = 50;

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
        $ranges = $this->input('ranges');

        $this->merge([
            'groups' => is_array($groups) ? array_map(
                fn (mixed $row): mixed => is_array($row) ? [...$row, 'name' => trim((string) ($row['name'] ?? ''))] : $row,
                array_values($groups),
            ) : $groups,
            'ranges' => is_array($ranges) ? array_map(
                fn (mixed $row): mixed => is_array($row) ? [
                    ...$row,
                    'prefix' => trim((string) ($row['prefix'] ?? '')),
                    'from' => trim((string) ($row['from'] ?? '')),
                    'to' => trim((string) ($row['to'] ?? '')),
                    'group_key' => filled($row['group_key'] ?? null) ? $row['group_key'] : null,
                ] : $row,
                array_values($ranges),
            ) : $ranges,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->property();
        $usesGroups = $this->usesGroups();
        $groupKeys = array_column(array_filter((array) $this->input('groups'), is_array(...)), 'key');

        $rules = [
            'use_groups' => ['required', 'boolean'],
            'groups' => $usesGroups ? ['required', 'array', 'min:1', 'max:'.self::MAX_GROUPS] : ['present', 'array', 'max:'.self::MAX_GROUPS],
            'groups.*.key' => ['required', 'string', 'max:40', 'distinct'],
            'groups.*.id' => [
                'nullable', 'integer', 'distinct',
                Rule::exists('room_groups', 'id')->where('property_id', $property->id),
            ],
            'groups.*.name' => ['required', 'string', 'max:100'],
            'ranges' => ['required', 'array', 'min:1', 'max:'.self::MAX_RANGES],
        ];

        foreach (array_keys((array) $this->input('ranges')) as $index) {
            $filled = $this->rangeIsFilled($index);

            $rules["ranges.{$index}.prefix"] = ['nullable', 'string', 'max:10'];
            $rules["ranges.{$index}.room_type_id"] = $filled ? [
                'required', 'integer',
                Rule::exists('room_types', 'id')
                    ->where('property_id', $property->id)
                    ->where('sell_unit', SellUnit::Room->value)
                    ->whereNull('archived_at'),
            ] : ['nullable'];
            $rules["ranges.{$index}.from"] = $filled ? ['required', 'regex:/^\d{1,6}$/'] : ['nullable'];
            $rules["ranges.{$index}.to"] = $filled ? ['required', 'regex:/^\d{1,6}$/'] : ['nullable'];
            $rules["ranges.{$index}.group_key"] = $usesGroups && $filled
                ? ['required', Rule::in($groupKeys)]
                : ['nullable'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ranges.*.from.regex' => __('Use digits only.'),
            'ranges.*.to.regex' => __('Use digits only.'),
            'ranges.*.group_key.required' => __('Choose a group.'),
            'ranges.*.group_key.in' => __('Choose a group.'),
            'groups.min' => __('Add at least one group, or choose No groups.'),
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
                $this->checkRanges($validator);
            },
        ];
    }

    /**
     * The groups to keep and create: empty when the owner picked No groups and the property has none.
     *
     * @return list<array{key: string, id: int|null, name: string}>
     */
    public function groups(): array
    {
        if (! $this->usesGroups()) {
            return [];
        }

        return array_map(fn (array $row): array => [
            'key' => (string) $row['key'],
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'name' => (string) $row['name'],
        ], array_values($this->validated('groups')));
    }

    /**
     * The filled range rows. Blank rows are dropped.
     *
     * @return list<array{room_type_id: int, group_key: string|null, prefix: string, suffix: string, from: string, to: string}>
     */
    public function ranges(): array
    {
        $usesGroups = $this->usesGroups();
        $ranges = [];

        foreach (array_values($this->validated('ranges')) as $index => $row) {
            if (! $this->rangeIsFilled($index)) {
                continue;
            }

            $ranges[] = [
                'room_type_id' => (int) $row['room_type_id'],
                'group_key' => $usesGroups ? $row['group_key'] : null,
                'prefix' => (string) ($row['prefix'] ?? ''),
                'suffix' => '',
                'from' => $row['from'],
                'to' => $row['to'],
            ];
        }

        return $ranges;
    }

    private function property(): Property
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return $property;
    }

    private function usesGroups(): bool
    {
        return $this->boolean('use_groups') || $this->property()->roomGroups()->exists();
    }

    private function rangeIsFilled(int|string $index): bool
    {
        $row = (array) data_get($this->all(), "ranges.{$index}", []);

        return filled($row['prefix'] ?? null) || filled($row['from'] ?? null) || filled($row['to'] ?? null);
    }

    /**
     * Group names must differ, ignoring case. A new name must also differ from a saved group that is not in the
     * table. Saved groups always are, so the table covers them.
     */
    private function checkGroupNames(Validator $validator): void
    {
        $seen = [];

        foreach (array_values((array) $this->input('groups')) as $index => $row) {
            $name = mb_strtolower((string) $row['name']);

            if (isset($seen[$name])) {
                $validator->errors()->add("groups.{$index}.name", __('Another room group already uses this name.'));
            }

            $seen[$name] = true;
        }
    }

    /**
     * Each range must be in order and within the limit, and no number may repeat across rows or be in use already.
     */
    private function checkRanges(Validator $validator): void
    {
        $generator = app(RoomNumberGenerator::class);
        $usedByRow = [];
        $filledCount = 0;

        foreach (array_values((array) $this->input('ranges')) as $index => $row) {
            if (! $this->rangeIsFilled($index)) {
                continue;
            }

            $filledCount++;

            if ((int) $row['to'] < (int) $row['from']) {
                $validator->errors()->add("ranges.{$index}.to", __('The last number must not be lower than the first.'));

                continue;
            }

            if ($generator->size(RoomNumberGenerator::MODE_RANGE, $row) > RoomNumberGenerator::MAX_ROOMS) {
                $validator->errors()->add("ranges.{$index}.to", __('Create at most :max rooms at a time.', ['max' => RoomNumberGenerator::MAX_ROOMS]));

                continue;
            }

            $numbers = array_column($generator->generate(RoomNumberGenerator::MODE_RANGE, $row), 'number');
            $usedByRow[$index] = $numbers;
        }

        $this->checkNumbers($validator, $usedByRow);

        if ($filledCount === 0 && ! $this->property()->rooms()->active()->exists()) {
            $validator->errors()->add('ranges', __('Add at least one range of rooms.'));
        }
    }

    /**
     * @param  array<int, list<string>>  $usedByRow  The room numbers of each valid range row.
     */
    private function checkNumbers(Validator $validator, array $usedByRow): void
    {
        $firstSeen = [];

        foreach ($usedByRow as $index => $numbers) {
            $repeated = [];

            foreach ($numbers as $number) {
                $lower = mb_strtolower($number);

                if (isset($firstSeen[$lower]) && $firstSeen[$lower] !== $index) {
                    $repeated[] = $number;
                }

                $firstSeen[$lower] ??= $index;
            }

            if ($repeated !== []) {
                $validator->errors()->add("ranges.{$index}.from", __('Also in another row: :numbers.', ['numbers' => $this->summary($repeated)]));
            }
        }

        $taken = app(RoomNumberAvailability::class)->taken($this->property(), array_merge(...array_values($usedByRow) ?: [[]]));

        foreach ($usedByRow as $index => $numbers) {
            $inRow = array_values(array_filter($taken, fn (string $number): bool => in_array($number, $numbers, true)));

            if ($inRow !== [] && ! $validator->errors()->has("ranges.{$index}.from")) {
                $validator->errors()->add("ranges.{$index}.from", __('Already in use: :numbers.', ['numbers' => $this->summary($inRow)]));
            }
        }
    }

    /**
     * @param  list<string>  $numbers
     */
    private function summary(array $numbers): string
    {
        $shown = array_slice(array_values(array_unique($numbers)), 0, 3);

        return implode(', ', $shown).(count($numbers) > 3 ? ' …' : '');
    }
}
