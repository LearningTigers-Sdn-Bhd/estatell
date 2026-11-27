<?php

namespace App\Http\Requests\Transfers;

use App\Enums\TransferDirection;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\TransferRoute;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add or change a route on the Transfers settings Routes tab. */
class TransferRouteRequest extends FormRequest
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
        $route = $this->route('transferRoute');

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'place' => trim((string) $this->input('place')),
            'minutes' => filled($this->input('minutes')) ? $this->input('minutes') : null,
            'shared_charge_item_id' => filled($this->input('shared_charge_item_id')) ? $this->input('shared_charge_item_id') : null,
            'private_charge_item_id' => filled($this->input('private_charge_item_id')) ? $this->input('private_charge_item_id') : null,
            // The Sheet has no Available switch, so an edit that sends none keeps the route's own state.
            'active' => $this->boolean('active', $route instanceof TransferRoute ? $route->active : true),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $route = $this->route('transferRoute');
        $routeId = $route instanceof TransferRoute ? $route->id : null;
        $chargeItem = function (string $attribute, mixed $value, Closure $fail) use ($property): void {
            if (! ChargeItem::query()->where('property_id', $property->id)->active()->whereNotNull('default_amount')->whereKey((int) $value)->exists()) {
                $fail(__('Pick an active charge item with a fixed price.'));
            }
        };

        return [
            'direction' => ['required', Rule::enum(TransferDirection::class)],
            'name' => [
                'required', 'string', 'max:60',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $routeId): void {
                    $taken = TransferRoute::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($routeId !== null, fn ($query) => $query->whereKeyNot($routeId))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another route already uses this name.'));
                    }
                },
            ],
            'place' => ['required', 'string', 'max:120'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'shared_charge_item_id' => ['nullable', 'integer', $chargeItem],
            'private_charge_item_id' => ['nullable', 'integer', $chargeItem],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array{direction: TransferDirection, name: string, place: string, minutes: ?int, shared_charge_item_id: ?int, private_charge_item_id: ?int, active: bool}
     */
    public function routeAttributes(): array
    {
        $optionalInt = fn (string $key): ?int => $this->validated($key) === null ? null : (int) $this->validated($key);

        return [
            'direction' => TransferDirection::from((string) $this->validated('direction')),
            'name' => (string) $this->validated('name'),
            'place' => (string) $this->validated('place'),
            'minutes' => $optionalInt('minutes'),
            'shared_charge_item_id' => $optionalInt('shared_charge_item_id'),
            'private_charge_item_id' => $optionalInt('private_charge_item_id'),
            'active' => (bool) $this->validated('active'),
        ];
    }
}
