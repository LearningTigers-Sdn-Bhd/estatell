<?php

namespace App\Http\Requests\Facilities;

use App\Enums\FacilityBookedBy;
use App\Enums\FacilityRateBasis;
use App\Models\ChargeItem;
use App\Models\Facility;
use App\Models\Property;
use App\Models\Tax;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add a facility in the Sheet, save its Details tab, or turn it on or off with an Available switch. */
class FacilityRequest extends FormRequest
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
        $facility = $this->route('facility');
        $chargeByHand = $this->boolean('charge_by_hand');

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'charge_by_hand' => $chargeByHand,
            'default_amount' => $chargeByHand || ! filled($this->input('default_amount')) ? null : $this->input('default_amount'),
            // A save that sends no Active keeps the facility's own state.
            'active' => $this->boolean('active', $facility instanceof Facility ? $facility->active : true),
        ]);

        if (is_array($this->input('tax_ids'))) {
            $this->merge(['tax_ids' => array_values(array_map(fn (mixed $id): int => (int) $id, $this->input('tax_ids')))]);
        }
    }

    /**
     * The name must be free among facilities and among charge items, because the facility's charge item takes it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $facility = $this->route('facility');
        $current = $facility instanceof Facility ? $facility : null;

        return [
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    $name = mb_strtolower((string) $value);

                    $facilityTaken = Facility::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(name) = ?', [$name])
                        ->when($current !== null, fn ($query) => $query->whereKeyNot($current->id))
                        ->exists();

                    if ($facilityTaken) {
                        $fail(__('Another facility already uses this name.'));

                        return;
                    }

                    $chargeItemTaken = ChargeItem::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(name) = ?', [$name])
                        ->when($current !== null, fn ($query) => $query->whereKeyNot($current->charge_item_id))
                        ->exists();

                    if ($chargeItemTaken) {
                        $fail(__('A charge item already uses this name. Choose another name.'));
                    }
                },
            ],
            'booked_by' => ['required', Rule::enum(FacilityBookedBy::class)],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'charge_by_hand' => ['boolean'],
            'default_amount' => ['exclude_if:charge_by_hand,true', 'required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'rate_basis' => ['required', Rule::enum(FacilityRateBasis::class)],
            'private_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'active' => ['boolean'],
            'tax_ids' => ['sometimes', 'array'],
            'tax_ids.*' => ['integer', 'distinct', Rule::exists(Tax::class, 'id')->where('property_id', $property->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'default_amount.required' => __('Enter a rate, or turn on Charge by hand.'),
        ];
    }

    /**
     * `tax_ids` is null when the form sent none (the Available switch), so the charge item keeps its taxes.
     * `private_amount` is there only when the form sent it, so the Available switch keeps it; an Item facility has none.
     *
     * @return array{name: string, booked_by: string, capacity: int, default_amount: ?string, rate_basis: string, active: bool, tax_ids: ?list<int>, private_amount?: ?string}
     */
    public function facilityAttributes(): array
    {
        $amount = $this->validated('default_amount');

        return [
            'name' => (string) $this->validated('name'),
            'booked_by' => (string) $this->validated('booked_by'),
            'capacity' => (int) $this->validated('capacity'),
            'default_amount' => $amount === null ? null : (string) $amount,
            'rate_basis' => (string) $this->validated('rate_basis'),
            'active' => (bool) $this->validated('active'),
            'tax_ids' => $this->has('tax_ids') ? array_values(array_map('intval', (array) $this->validated('tax_ids', []))) : null,
            ...($this->has('private_amount') ? ['private_amount' => $this->validated('booked_by') === FacilityBookedBy::Person->value && filled($this->validated('private_amount')) ? (string) $this->validated('private_amount') : null] : []),
        ];
    }
}
