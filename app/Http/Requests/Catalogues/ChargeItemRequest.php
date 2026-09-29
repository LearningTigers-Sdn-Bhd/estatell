<?php

namespace App\Http\Requests\Catalogues;

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
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
        ];
    }
}
