<?php

namespace App\Http\Requests\Boats;

use App\Enums\BoatDirection;
use App\Enums\TransactionCategory;
use App\Models\BoatDeparture;
use App\Models\ChargeItem;
use App\Models\Property;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

class BoatDepartureRequest extends FormRequest
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
            'pickup_point' => filled($this->input('pickup_point')) ? trim((string) $this->input('pickup_point')) : null,
            'seats' => filled($this->input('seats')) ? $this->input('seats') : null,
            'charge_item_id' => filled($this->input('charge_item_id')) ? $this->input('charge_item_id') : null,
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

        $current = $this->route('boatDeparture');
        if (! $current instanceof BoatDeparture) {
            $current = null;
        }

        return [
            'direction' => ['required', Rule::enum(BoatDirection::class)],
            'leaves_at' => [
                'bail', 'required', 'date_format:H:i',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    $direction = BoatDirection::tryFrom((string) $this->input('direction'));

                    if ($direction === null) {
                        return;
                    }

                    $taken = BoatDeparture::query()
                        ->where('property_id', $property->id)
                        ->where('direction', $direction)
                        ->where('leaves_at', $value)
                        ->when($current instanceof BoatDeparture, fn (Builder $query) => $query->whereKeyNot($current->id))
                        ->exists();

                    if ($taken) {
                        $fail(__('A :direction already leaves at :time.', ['direction' => Str::lower($direction->label()), 'time' => $value]));
                    }
                },
            ],
            'arrives_at' => ['bail', 'required', 'date_format:H:i', 'after:leaves_at'],
            'pickup_point' => ['nullable', 'string', 'max:150'],
            'seats' => ['nullable', 'integer', 'min:1'],
            'charge_item_id' => [
                'nullable', 'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    // A departure keeps its current fare even when that fare is now unavailable.
                    if ($value === null || (int) $value === $current?->charge_item_id) {
                        return;
                    }

                    $isAvailableFare = ChargeItem::query()
                        ->where('property_id', $property->id)
                        ->whereKey($value)
                        ->where('active', true)
                        ->whereHas('transactionCode', fn (Builder $query) => $query->where('category', TransactionCategory::Transport->value))
                        ->exists();

                    if (! $isAvailableFare) {
                        $fail(__('Choose a fare from this property.'));
                    }
                },
            ],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'arrives_at.after' => __('Arrives must be after Leaves.'),
        ];
    }
}
