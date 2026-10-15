<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\OnboardingStep;
use App\Enums\TransactionKind;
use App\Models\Discount;
use App\Models\Property;
use App\Models\TransactionCode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * The Discounts table of onboarding. A row id must be a discount of this property. Names are unique across the
 * property's discounts, ignoring case. The value follows the type: Percent 1 to 100, Fixed above 0, Open none. A
 * Selected scope needs the room charges or at least one charge code of this property.
 */
class SaveDiscountsRequest extends OnboardingStepRequest
{
    public const MAX_ROWS = 20;

    protected function step(): OnboardingStep
    {
        return OnboardingStep::Discounts;
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
                'value' => filled($row['value'] ?? null) ? $row['value'] : null,
                'room_charges' => (bool) ($row['room_charges'] ?? false),
                'transaction_code_ids' => is_array($row['transaction_code_ids'] ?? null)
                    ? array_values(array_map(fn (mixed $id): mixed => is_numeric($id) ? (int) $id : $id, $row['transaction_code_ids']))
                    : ($row['transaction_code_ids'] ?? []),
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

        $rules = [
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*.id' => [
                'nullable', 'integer', 'distinct',
                function (string $attribute, mixed $value, Closure $fail) use ($property): void {
                    if (! $property->discounts()->whereKey($value)->exists()) {
                        $fail(__('Choose a discount of this property.'));
                    }
                },
            ],
            'rows.*.name' => [
                'required', 'string', 'max:100',
                fn (string $attribute, mixed $value, Closure $fail) => $this->checkName($property, $attribute, (string) $value, $fail),
            ],
            'rows.*.type' => ['required', Rule::enum(DiscountType::class)],
            'rows.*.allow_override' => ['required', 'boolean'],
            'rows.*.scope' => ['required', Rule::enum(DiscountScope::class)],
            'rows.*.active' => ['required', 'boolean'],
            'rows.*.room_charges' => ['required', 'boolean'],
            'rows.*.transaction_code_ids' => [
                'present', 'array',
                function (string $attribute, mixed $value, Closure $fail) use ($property): void {
                    if (! is_array($value) || $value === []) {
                        return;
                    }

                    if (count(array_unique($value)) !== count($value)) {
                        $fail(__('Choose each charge once.'));

                        return;
                    }

                    $valid = TransactionCode::query()
                        ->where('property_id', $property->id)
                        ->where('kind', TransactionKind::Charge)
                        ->whereKey($value)
                        ->count();

                    if ($valid !== count($value)) {
                        $fail(__('Choose charges from this property.'));
                    }
                },
            ],
            'rows.*.transaction_code_ids.*' => ['integer'],
        ];

        foreach (array_values((array) $this->input('rows')) as $index => $row) {
            $rules["rows.{$index}.value"] = match (is_array($row) ? ($row['type'] ?? null) : null) {
                DiscountType::Percent->value => ['required', 'numeric', 'decimal:0,2', 'min:1', 'max:100'],
                DiscountType::Fixed->value => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
                default => ['nullable'],
            };
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $afterValidator): void {
            foreach (array_values((array) $this->input('rows')) as $index => $row) {
                if (is_array($row)
                    && ($row['scope'] ?? null) === DiscountScope::Selected->value
                    && empty($row['room_charges'])
                    && empty($row['transaction_code_ids'])) {
                    $afterValidator->errors()->add("rows.{$index}.transaction_code_ids", __('Choose at least one charge.'));
                }
            }
        });
    }

    /**
     * @return list<array{id: int|null, name: string, type: DiscountType, value: string|null, allow_override: bool, scope: DiscountScope, active: bool, room_charges: bool, transaction_code_ids: list<int>}>
     */
    public function rows(): array
    {
        return array_map(function (array $row): array {
            $type = DiscountType::from($row['type']);

            return [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'name' => $row['name'],
                'type' => $type,
                'value' => $type === DiscountType::Open || ! isset($row['value']) ? null : (string) $row['value'],
                'allow_override' => (bool) $row['allow_override'],
                'scope' => DiscountScope::from($row['scope']),
                'active' => (bool) $row['active'],
                'room_charges' => (bool) $row['room_charges'],
                'transaction_code_ids' => array_map(fn (mixed $id): int => (int) $id, $row['transaction_code_ids']),
            ];
        }, array_values($this->validated('rows')));
    }

    /**
     * The name must differ from the other rows and from every discount of the property that has no row of its own,
     * ignoring case.
     */
    private function checkName(Property $property, string $attribute, string $name, Closure $fail): void
    {
        $index = (int) explode('.', $attribute)[1];
        $lower = mb_strtolower($name);
        $rows = array_values((array) $this->input('rows'));

        foreach ($rows as $position => $row) {
            if ($position !== $index && mb_strtolower(trim((string) ($row['name'] ?? ''))) === $lower) {
                $fail(__('Another discount in this table uses this name.'));

                return;
            }
        }

        $taken = Discount::query()
            ->where('property_id', $property->id)
            ->whereRaw('lower(name) = ?', [$lower])
            ->whereNotIn('id', array_filter(array_column($rows, 'id')))
            ->exists();

        if ($taken) {
            $fail(__('Another discount already uses this name.'));
        }
    }
}
