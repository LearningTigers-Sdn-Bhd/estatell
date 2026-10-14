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
            ...($this->has('active') ? ['active' => $this->boolean('active')] : []),
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
                    if ($value === null) {
                        return;
                    }

                    $fare = $this->fareFor($value, $property);

                    if ($fare === null) {
                        $fail(__('Choose a fare from this property.'));

                        return;
                    }

                    if (! $fare->active) {
                        // An available departure needs an available fare. A create is always available, and an edit that
                        // sends no `active` keeps the departure's state; an edit that sends `active` is checked by that rule.
                        // An unavailable departure keeps its fare, but cannot take a new unavailable one.
                        if ($this->isMethod('POST') || (! $this->has('active') && $this->savesAvailable($current))) {
                            $fail(__('Turn the fare :name on first.', ['name' => $fare->name]));
                        } elseif ((int) $value !== $current?->charge_item_id && ! $this->savesAvailable($current)) {
                            $fail(__('Choose a fare from this property.'));
                        }
                    }

                    if ($fare->auto_add) {
                        $fail(__('This fare is added to every new booking. Turn that off first.'));
                    }
                },
            ],
            'active' => [
                'sometimes', 'boolean',
                function (string $attribute, mixed $value, Closure $fail) use ($property): void {
                    // A create reports its fare on `charge_item_id`, the field the hotelier picks.
                    if ($this->isMethod('POST') || ! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                        return;
                    }

                    $fare = $this->input('charge_item_id') === null ? null : $this->fareFor($this->input('charge_item_id'), $property);

                    if ($fare !== null && ! $fare->active) {
                        $fail(__('Turn the fare :name on first.', ['name' => $fare->name]));
                    }
                },
            ],
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

    /** A Transport charge item of the property, or null. Active or not. */
    private function fareFor(mixed $value, Property $property): ?ChargeItem
    {
        return ChargeItem::query()
            ->where('property_id', $property->id)
            ->whereKey($value)
            ->whereHas('transactionCode', fn (Builder $query) => $query->where('category', TransactionCategory::Transport->value))
            ->first();
    }

    /**
     * Whether this save makes the departure available: a create always does, an edit does when `active` is sent true,
     * and an edit without `active` keeps the departure's own state.
     */
    private function savesAvailable(?BoatDeparture $current): bool
    {
        if ($this->has('active')) {
            return filter_var($this->input('active'), FILTER_VALIDATE_BOOLEAN);
        }

        return $current->active ?? true;
    }
}
