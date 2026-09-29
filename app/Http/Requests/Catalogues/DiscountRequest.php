<?php

namespace App\Http\Requests\Catalogues;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\TransactionKind;
use App\Models\Discount;
use App\Models\Property;
use App\Models\TransactionCode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

class DiscountRequest extends FormRequest
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
        $codeIds = $this->input('transaction_code_ids', []);

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => Str::of((string) $this->input('code'))->trim()->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString(),
            'value' => filled($this->input('value')) ? $this->input('value') : null,
            'transaction_code_ids' => is_array($codeIds)
                ? array_values(array_map(fn (mixed $id): int => (int) $id, $codeIds))
                : $codeIds,
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

        $current = $this->route('discount');
        if (! $current instanceof Discount) {
            $current = null;
        }

        return [
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    $taken = Discount::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($current instanceof Discount, fn (Builder $query) => $query->whereKeyNot($current->id))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another discount already uses this name.'));
                    }
                },
            ],
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Z0-9]+(?:_[A-Z0-9]+)*$/',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    $taken = TransactionCode::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(code) = ?', [mb_strtolower((string) $value)])
                        ->when($current instanceof Discount, fn (Builder $query) => $query->whereKeyNot($current->transaction_code_id))
                        ->exists();

                    if ($taken) {
                        $fail(__('The code :code is already in use.', ['code' => $value]));
                    }
                },
            ],
            'type' => ['required', Rule::enum(DiscountType::class)],
            'value' => match ($this->input('type')) {
                DiscountType::Percent->value => ['required', 'numeric', 'decimal:0,2', 'min:1', 'max:100'],
                DiscountType::Fixed->value => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
                default => ['nullable'],
            },
            'allow_override' => ['required', 'boolean'],
            'scope' => ['required', Rule::enum(DiscountScope::class)],
            'active' => ['required', 'boolean'],
            'transaction_code_ids' => [
                'present', 'array', 'distinct',
                Rule::requiredIf($this->input('scope') === DiscountScope::Selected->value),
                function (string $attribute, mixed $value, Closure $fail) use ($property): void {
                    if (! is_array($value) || $this->input('scope') !== DiscountScope::Selected->value) {
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
            'transaction_code_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'transaction_code_ids.required' => __('Choose at least one charge.'),
        ];
    }
}
