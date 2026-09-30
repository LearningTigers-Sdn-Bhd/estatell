<?php

namespace App\Http\Requests\Property;

use App\Models\Property;
use App\Models\PropertyAgeGroup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

class SaveAgeGroupsRequest extends FormRequest
{
    /** The oldest age a group may end at. Adults start after the last group. */
    public const MAX_AGE = 17;

    /** The `can:settings.manage` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $groups = $this->input('groups');

        if (! is_array($groups)) {
            return;
        }

        $this->merge(['groups' => array_map(
            fn (mixed $group): mixed => is_array($group) && isset($group['label']) && is_string($group['label'])
                ? [...$group, 'label' => trim($group['label'])]
                : $group,
            $groups,
        )]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');
        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        $groups = $this->input('groups');
        $groups = is_array($groups) ? array_values($groups) : [];

        return [
            'groups' => ['present', 'array', 'max:'.(self::MAX_AGE + 1)],
            'groups.*.id' => [
                'nullable', 'integer', 'distinct',
                function (string $attribute, mixed $value, Closure $fail) use ($property): void {
                    if ($value !== null && ! PropertyAgeGroup::query()->whereBelongsTo($property)->whereKey($value)->exists()) {
                        $fail(__('This age group no longer exists.'));
                    }
                },
            ],
            'groups.*.label' => [
                'required', 'string', 'max:50',
                function (string $attribute, mixed $value, Closure $fail) use ($groups): void {
                    $same = collect($groups)
                        ->filter(fn (mixed $group): bool => is_array($group) && is_string($group['label'] ?? null) && mb_strtolower($group['label']) === mb_strtolower((string) $value))
                        ->count();

                    if ($same > 1) {
                        $fail(__('Another age group already uses this label.'));
                    }
                },
            ],
            'groups.*.max_age' => [
                'required', 'integer', 'min:0', 'max:'.self::MAX_AGE,
                function (string $attribute, mixed $value, Closure $fail) use ($groups): void {
                    $index = (int) explode('.', $attribute)[1];
                    $previous = $groups[$index - 1]['max_age'] ?? null;

                    if ($index > 0 && is_numeric($previous) && is_numeric($value) && (int) $value <= (int) $previous) {
                        $fail(__('This age must be higher than the group above.'));
                    }
                },
            ],
        ];
    }

    /**
     * @return list<array{id: int|null, label: string, max_age: int}>
     */
    public function groups(): array
    {
        return array_map(
            fn (array $group): array => [
                'id' => isset($group['id']) ? (int) $group['id'] : null,
                'label' => $group['label'],
                'max_age' => (int) $group['max_age'],
            ],
            array_values($this->validated('groups')),
        );
    }
}
