<?php

namespace App\Http\Requests\Catalogues;

use App\Enums\TaxRateType;
use App\Enums\TransactionKind;
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

class TaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $applicableTransactionCodeIds = $this->input('applicable_transaction_code_ids', []);
        $registrationNumber = filled($this->input('registration_number'))
            ? Str::of((string) $this->input('registration_number'))->trim()->upper()->replaceMatches('/\s+/', ' ')->toString()
            : null;

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => Str::of((string) $this->input('code'))->trim()->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString(),
            'registration_number' => $registrationNumber,
            'applicable_transaction_code_ids' => is_array($applicableTransactionCodeIds)
                ? array_values(array_map(fn (mixed $id): int => (int) $id, $applicableTransactionCodeIds))
                : $applicableTransactionCodeIds,
        ]);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $property = $this->route('property');
        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        $current = $this->route('tax');
        if (! $current instanceof Tax) {
            $current = null;
        }

        return [
            'name' => ['required', 'string', 'max:100', $this->uniqueTaxValue($property, $current, 'name', __('Another tax already uses this name.'))],
            'code' => [
                'required', 'string', 'max:50', 'regex:/^[A-Z0-9]+(?:_[A-Z0-9]+)*$/',
                $this->uniqueTaxValue($property, $current, 'code', __('Another tax already uses this code.')),
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    if ($current instanceof Tax && $current->transactionCode()->whereNotNull('system_key')->exists()) {
                        return;
                    }

                    $query = TransactionCode::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(code) = ?', [mb_strtolower('TAX_'.(string) $value)])
                        ->when($current instanceof Tax, fn (Builder $builder) => $builder->whereKeyNot($current->transaction_code_id));

                    if ($query->exists()) {
                        $fail(__('The posting code TAX_:code is already in use.', ['code' => $value]));
                    }
                },
            ],
            'rate_type' => ['required', Rule::enum(TaxRateType::class)],
            'rate' => [
                'required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->input('rate_type') === TaxRateType::Percent->value && (float) $value > 100) {
                        $fail(__('A percentage tax cannot exceed 100%.'));
                    }
                },
            ],
            'is_inclusive' => ['required', 'boolean'],
            'foreign_guests_only' => ['required', 'boolean'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'enabled' => ['required', 'boolean'],
            'applicable_transaction_code_ids' => [
                'present', 'array', 'distinct',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    if (! is_array($value)) {
                        return;
                    }

                    if ($this->boolean('enabled') && $value === []) {
                        $fail(__('Choose at least one charge code before enabling this tax.'));

                        return;
                    }

                    $existingIds = $current instanceof Tax
                        ? $current->applicableTransactionCodes()->pluck('transaction_codes.id')->all()
                        : [];
                    $validCount = TransactionCode::query()
                        ->where('property_id', $property->id)
                        ->where('kind', TransactionKind::Charge)
                        ->where(function (Builder $query) use ($existingIds): void {
                            $query->active()->orWhereKey($existingIds);
                        })
                        ->whereKey($value)
                        ->count();

                    if ($validCount !== count($value)) {
                        $fail(__('Choose active charge codes from this property.'));
                    }
                },
            ],
            'applicable_transaction_code_ids.*' => ['integer'],
        ];
    }

    private function uniqueTaxValue(Property $property, ?Tax $current, string $column, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($property, $current, $column, $message): void {
            $expression = match ($column) {
                'name' => 'lower(name) = ?',
                'code' => 'lower(code) = ?',
                default => throw new LogicException('Unsupported unique tax column.'),
            };

            $taken = Tax::query()
                ->where('property_id', $property->id)
                ->whereRaw($expression, [mb_strtolower((string) $value)])
                ->when($current instanceof Tax, fn (Builder $query) => $query->whereKeyNot($current->id))
                ->exists();

            if ($taken) {
                $fail($message);
            }
        };
    }
}
