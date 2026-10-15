<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\OnboardingStep;
use App\Enums\TaxRateType;
use App\Models\Property;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * The Taxes table of onboarding. Each row describes one tax with the bounds of TaxRequest, plus whether it applies to
 * rooms. With `no_tax` ("We don't charge tax") the rows are ignored. A row id must be a tax of this property.
 */
class SaveTaxesRequest extends OnboardingStepRequest
{
    public const MAX_ROWS = 20;

    protected function step(): OnboardingStep
    {
        return OnboardingStep::Taxes;
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
                'registration_number' => filled($row['registration_number'] ?? null)
                    ? Str::of((string) $row['registration_number'])->trim()->upper()->replaceMatches('/\s+/', ' ')->toString()
                    : null,
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

        if ($this->boolean('no_tax')) {
            return ['no_tax' => ['required', 'boolean']];
        }

        return [
            'no_tax' => ['required', 'boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*.id' => [
                'nullable', 'integer', 'distinct',
                Rule::exists('taxes', 'id')->where('property_id', $property->id),
            ],
            'rows.*.name' => [
                'required', 'string', 'max:100',
                fn (string $attribute, mixed $value, Closure $fail) => $this->checkName($property, $attribute, (string) $value, $fail),
            ],
            'rows.*.rate_type' => ['required', Rule::enum(TaxRateType::class)],
            'rows.*.rate' => [
                'required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $index = (int) explode('.', $attribute)[1];

                    if (data_get($this->input('rows'), "{$index}.rate_type") === TaxRateType::Percent->value && (float) $value > 100) {
                        $fail(__('A percentage tax cannot exceed 100%.'));
                    }
                },
            ],
            'rows.*.is_inclusive' => ['required', 'boolean'],
            'rows.*.foreign_guests_only' => ['required', 'boolean'],
            'rows.*.collect_at_check_in' => ['required', 'boolean'],
            'rows.*.registration_number' => ['nullable', 'string', 'max:100'],
            'rows.*.enabled' => ['required', 'boolean'],
            'rows.*.applies_to_rooms' => ['required', 'boolean'],
        ];
    }

    public function noTax(): bool
    {
        return $this->boolean('no_tax');
    }

    /**
     * @return list<array{id: int|null, name: string, rate_type: TaxRateType, rate: string, is_inclusive: bool, foreign_guests_only: bool, collect_at_check_in: bool, registration_number: string|null, enabled: bool, applies_to_rooms: bool}>
     */
    public function rows(): array
    {
        if ($this->noTax()) {
            return [];
        }

        return array_map(fn (array $row): array => [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'name' => $row['name'],
            'rate_type' => TaxRateType::from($row['rate_type']),
            'rate' => (string) $row['rate'],
            'is_inclusive' => (bool) $row['is_inclusive'],
            'foreign_guests_only' => (bool) $row['foreign_guests_only'],
            'collect_at_check_in' => (bool) $row['collect_at_check_in'],
            'registration_number' => $row['registration_number'] ?? null,
            'enabled' => (bool) $row['enabled'],
            'applies_to_rooms' => (bool) $row['applies_to_rooms'],
        ], array_values($this->validated('rows')));
    }

    /**
     * The name must differ from the other rows and from every saved tax that has no row of its own, ignoring case.
     */
    private function checkName(Property $property, string $attribute, string $name, Closure $fail): void
    {
        $index = (int) explode('.', $attribute)[1];
        $lower = mb_strtolower($name);
        $rows = array_values((array) $this->input('rows'));

        foreach ($rows as $position => $row) {
            if ($position !== $index && mb_strtolower(trim((string) ($row['name'] ?? ''))) === $lower) {
                $fail(__('Another tax in this table uses this name.'));

                return;
            }
        }

        $keptIds = array_filter(array_column($rows, 'id'));

        $taken = $property->taxes()
            ->whereRaw('lower(name) = ?', [$lower])
            ->whereNotIn('id', $keptIds)
            ->exists();

        if ($taken) {
            $fail(__('Another tax already uses this name.'));
        }
    }
}
