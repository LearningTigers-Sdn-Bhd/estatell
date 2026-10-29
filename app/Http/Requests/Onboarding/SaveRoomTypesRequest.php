<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\OnboardingStep;
use App\Enums\SellUnit;
use App\Models\Property;
use App\Models\RoomType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * The room types table of onboarding. Each row asks for a name, what it sells and the guest limits; the bounds are
 * those of RoomTypeRequest. A row id must be an active room type of this property. Sold by cannot change on a saved
 * room type that has rooms, as in RoomTypeRequest.
 */
class SaveRoomTypesRequest extends OnboardingStepRequest
{
    public const MAX_ROWS = 50;

    protected function step(): OnboardingStep
    {
        return OnboardingStep::RoomTypes;
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
        $rows = $this->input('rows');

        if (! is_array($rows)) {
            return;
        }

        $this->merge(['rows' => array_map(
            fn (mixed $row): mixed => is_array($row) ? [
                ...$row,
                'name' => trim((string) ($row['name'] ?? '')),
                'max_children' => $row['max_children'] ?? 0,
            ] : $row,
            array_values($rows),
        )]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return [
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*.id' => [
                'nullable', 'integer', 'distinct',
                Rule::exists('room_types', 'id')
                    ->where('property_id', $property->id)
                    ->whereNull('archived_at'),
            ],
            'rows.*.sell_unit' => [
                'required', Rule::enum(SellUnit::class),
                fn (string $attribute, mixed $value, Closure $fail) => $this->checkSellUnit($property, $attribute, $value, $fail),
            ],
            'rows.*.name' => [
                'required', 'string', 'max:100',
                fn (string $attribute, mixed $value, Closure $fail) => $this->checkName($property, $attribute, (string) $value, $fail),
            ],
            'rows.*.max_adults' => ['required', 'integer', 'min:1', 'max:50'],
            'rows.*.max_children' => ['required', 'integer', 'min:0', 'max:50'],
        ];
    }

    /**
     * @return list<array{id: int|null, name: string, sell_unit: SellUnit, max_adults: int, max_children: int}>
     */
    public function rows(): array
    {
        return array_map(fn (array $row): array => [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'name' => $row['name'],
            'sell_unit' => SellUnit::from($row['sell_unit']),
            'max_adults' => (int) $row['max_adults'],
            'max_children' => (int) $row['max_children'],
        ], array_values($this->validated('rows')));
    }

    /**
     * A saved room type keeps its sell unit once it has any room, archived ones included.
     */
    private function checkSellUnit(Property $property, string $attribute, mixed $value, Closure $fail): void
    {
        $index = (int) explode('.', $attribute)[1];
        $id = ((array) $this->input('rows'))[$index]['id'] ?? null;

        if ($id === null || ! is_string($value)) {
            return;
        }

        $locked = RoomType::query()
            ->where('property_id', $property->id)
            ->whereKey((int) $id)
            ->where('sell_unit', '!=', $value)
            ->whereHas('rooms')
            ->exists();

        if ($locked) {
            $fail(__('Sold by cannot change while this room type has rooms.'));
        }
    }

    /**
     * The name must differ from the other rows and from the saved name of another kept row. The last one keeps a swap
     * of two names from hitting the unique index.
     */
    private function checkName(Property $property, string $attribute, string $name, Closure $fail): void
    {
        $index = (int) explode('.', $attribute)[1];
        $lower = mb_strtolower($name);
        $rows = array_values((array) $this->input('rows'));
        $keptIds = array_filter(array_column($rows, 'id'));

        foreach ($rows as $position => $row) {
            if ($position !== $index && mb_strtolower(trim((string) ($row['name'] ?? ''))) === $lower) {
                $fail(__('Another room type in this table uses this name.'));

                return;
            }
        }

        $ownId = $rows[$index]['id'] ?? null;

        $taken = RoomType::query()
            ->where('property_id', $property->id)
            ->active()
            ->whereRaw('lower(name) = ?', [$lower])
            ->whereIn('id', $keptIds)
            ->when($ownId, fn ($query, $id) => $query->where('id', '!=', $id))
            ->exists();

        if ($taken) {
            $fail(__('Another active room type already uses this name.'));
        }
    }
}
