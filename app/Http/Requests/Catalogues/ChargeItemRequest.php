<?php

namespace App\Http\Requests\Catalogues;

use App\Enums\ChargingUnit;
use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\TransactionCode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
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
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => filled($this->input('description')) ? trim((string) $this->input('description')) : null,
            'transaction_code_id' => $this->integer('transaction_code_id'),
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
            'description' => ['nullable', 'string', 'max:2000'],
            'transaction_code_id' => [
                'required', 'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    if (! is_int($value)) {
                        return;
                    }

                    $transactionCode = TransactionCode::query()
                        ->where('property_id', $property->id)
                        ->where('kind', TransactionKind::Charge)
                        ->whereKey($value)
                        ->first();

                    if ($transactionCode === null) {
                        $fail(__('Choose a charge transaction code from this property.'));

                        return;
                    }

                    $isUnchanged = $current instanceof ChargeItem && $current->transaction_code_id === $transactionCode->id;

                    if (! $transactionCode->active && ! $isUnchanged) {
                        $fail(__('Choose an active transaction code.'));
                    }
                },
            ],
            'default_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'charging_unit' => ['required', Rule::enum(ChargingUnit::class)],
            'allow_override' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ];
    }
}
