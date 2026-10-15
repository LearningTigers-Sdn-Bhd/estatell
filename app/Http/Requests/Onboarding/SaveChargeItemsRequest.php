<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\ChargingUnit;
use App\Enums\OnboardingStep;
use App\Enums\TransactionCategory;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\Tax;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * The Charge items table of onboarding. A row id must be a charge item of this property that the table shows (not
 * Transport). The category counts only on new rows. Names are unique across the property's charge items, and taxes
 * must be taxes of this property. Amounts follow ChargeItemRequest.
 */
class SaveChargeItemsRequest extends OnboardingStepRequest
{
    public const MAX_ROWS = 20;

    protected function step(): OnboardingStep
    {
        return OnboardingStep::ChargeItems;
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
                'default_amount' => filled($row['default_amount'] ?? null) ? $row['default_amount'] : null,
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
                function (string $attribute, mixed $value, Closure $fail) use ($property): void {
                    $shown = $property->chargeItems()
                        ->whereKey($value)
                        ->whereHas('transactionCode', fn (Builder $query) => $query->where('category', '!=', TransactionCategory::Transport->value))
                        ->exists();

                    if (! $shown) {
                        $fail(__('Choose a charge item of this property.'));
                    }
                },
            ],
            'rows.*.name' => [
                'required', 'string', 'max:100',
                fn (string $attribute, mixed $value, Closure $fail) => $this->checkName($property, $attribute, (string) $value, $fail),
            ],
            'rows.*.category' => ['nullable', Rule::enum(TransactionCategory::class)->only(TransactionCategory::onboardingChargeItemCases())],
            'rows.*.default_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'rows.*.charging_unit' => ['required', Rule::enum(ChargingUnit::class)],
            'rows.*.allow_override' => ['required', 'boolean'],
            'rows.*.active' => ['required', 'boolean'],
            'rows.*.tax_ids' => [
                'present', 'array',
                function (string $attribute, mixed $value, Closure $fail) use ($property): void {
                    if (! is_array($value)) {
                        return;
                    }

                    if (count(array_unique($value)) !== count($value)) {
                        $fail(__('Choose each tax once.'));

                        return;
                    }

                    if (Tax::query()->where('property_id', $property->id)->whereKey($value)->count() !== count($value)) {
                        $fail(__('Choose taxes from this property.'));
                    }
                },
            ],
            'rows.*.tax_ids.*' => ['integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $afterValidator): void {
            foreach (array_values((array) $this->input('rows')) as $index => $row) {
                if (is_array($row) && empty($row['id']) && empty($row['category'])) {
                    $afterValidator->errors()->add("rows.{$index}.category", __('Choose a category.'));
                }
            }
        });
    }

    /**
     * @return list<array{id: int|null, name: string, category: TransactionCategory|null, default_amount: string|null, charging_unit: ChargingUnit, allow_override: bool, active: bool, tax_ids: list<int>}>
     */
    public function rows(): array
    {
        return array_map(fn (array $row): array => [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'name' => $row['name'],
            'category' => isset($row['category']) ? TransactionCategory::from($row['category']) : null,
            'default_amount' => isset($row['default_amount']) ? (string) $row['default_amount'] : null,
            'charging_unit' => ChargingUnit::from($row['charging_unit']),
            'allow_override' => (bool) $row['allow_override'],
            'active' => (bool) $row['active'],
            'tax_ids' => array_map(fn (mixed $id): int => (int) $id, $row['tax_ids']),
        ], array_values($this->validated('rows')));
    }

    /**
     * The name must differ from the other rows and from every charge item of the property that has no row of its own
     * (Transport), ignoring case.
     */
    private function checkName(Property $property, string $attribute, string $name, Closure $fail): void
    {
        $index = (int) explode('.', $attribute)[1];
        $lower = mb_strtolower($name);
        $rows = array_values((array) $this->input('rows'));

        foreach ($rows as $position => $row) {
            if ($position !== $index && mb_strtolower(trim((string) ($row['name'] ?? ''))) === $lower) {
                $fail(__('Another charge item in this table uses this name.'));

                return;
            }
        }

        $keptIds = array_filter(array_column($rows, 'id'));

        $taken = ChargeItem::query()
            ->where('property_id', $property->id)
            ->whereRaw('lower(name) = ?', [$lower])
            ->whereNotIn('id', $keptIds)
            ->exists();

        if ($taken) {
            $fail(__('Another charge item already uses this name.'));
        }
    }
}
