<?php

namespace App\Http\Requests\Catalogues;

use App\Enums\AgeBandPricing;
use App\Enums\ChargingUnit;
use App\Enums\TransactionCategory;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\Tax;
use App\Models\TransactionCode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

class ChargeItemRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $taxIds = $this->input('tax_ids', []);

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => Str::of((string) $this->input('code'))->trim()->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString(),
            'description' => filled($this->input('description')) ? trim((string) $this->input('description')) : null,
            'default_amount' => filled($this->input('default_amount')) ? $this->input('default_amount') : null,
            'tax_ids' => is_array($taxIds)
                ? array_values(array_map(fn (mixed $id): int => (int) $id, $taxIds))
                : $taxIds,
        ]);
    }

    /**
     * Creating takes only the name, code and category; the edit page sets the rest.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = $this->allRules();

        return $this->isMethod('POST') ? Arr::only($rules, ['name', 'code', 'category']) : $rules;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    private function allRules(): array
    {
        $property = $this->route('property');
        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        $current = $this->route('chargeItem');
        if (! $current instanceof ChargeItem) {
            $current = null;
        }

        return [
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    $taken = ChargeItem::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($current instanceof ChargeItem, fn (Builder $query) => $query->whereKeyNot($current->id))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another charge item already uses this name.'));
                    }
                },
            ],
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Z0-9]+(?:_[A-Z0-9]+)*$/',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    $taken = TransactionCode::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(code) = ?', [mb_strtolower((string) $value)])
                        ->when($current instanceof ChargeItem, fn (Builder $query) => $query->whereKeyNot($current->transaction_code_id))
                        ->exists();

                    if ($taken) {
                        $fail(__('The code :code is already in use.', ['code' => $value]));
                    }
                },
            ],
            'category' => ['required', Rule::enum(TransactionCategory::class)->only(TransactionCategory::chargeItemCases())],
            'description' => ['nullable', 'string', 'max:2000'],
            'default_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'charging_unit' => ['required', Rule::enum(ChargingUnit::class)],
            'allow_override' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
            'tax_ids' => [
                'present', 'array', 'distinct',
                function (string $attribute, mixed $value, Closure $fail) use ($property): void {
                    if (! is_array($value)) {
                        return;
                    }

                    if (Tax::query()->where('property_id', $property->id)->whereKey($value)->count() !== count($value)) {
                        $fail(__('Choose taxes from this property.'));
                    }
                },
            ],
            'tax_ids.*' => ['integer'],
            'child_prices' => [
                'sometimes', 'array',
                function (string $attribute, mixed $value, Closure $fail) use ($property): void {
                    $unit = ChargingUnit::tryFrom((string) $this->input('charging_unit'));

                    if (! $unit?->pricesChildren()) {
                        return;
                    }

                    $groupIds = $property->ageGroups()->pluck('id')->all();
                    $sent = is_array($value) ? array_column($value, 'property_age_group_id') : [];

                    if (count($sent) !== count($groupIds) || array_diff($sent, $groupIds) !== []) {
                        $fail(__('The age groups changed. Reload the page and try again.'));
                    }
                },
            ],
            'child_prices.*.property_age_group_id' => ['required', 'integer', 'distinct'],
            'child_prices.*.pricing' => [
                'required', Rule::enum(AgeBandPricing::class),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === AgeBandPricing::Percent->value && ! filled($this->input('default_amount'))) {
                        $fail(__('A percentage needs a default amount. Choose Free or a fixed amount.'));
                    }
                },
            ],
            'child_prices.*.value' => [
                'required_if:child_prices.*.pricing,percent,fixed', 'nullable', 'decimal:0,2',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $index = explode('.', $attribute)[1];
                    $pricing = $this->input("child_prices.{$index}.pricing");

                    if ($pricing === AgeBandPricing::Percent->value && (! is_numeric($value) || $value <= 0 || $value > 100)) {
                        $fail(__('Enter a percentage from 1 to 100.'));
                    }

                    if ($pricing === AgeBandPricing::Fixed->value && (! is_numeric($value) || $value < 0 || $value > 9999999999)) {
                        $fail(__('Enter an amount of 0 or more.'));
                    }
                },
            ],
        ];
    }
}
