<?php

namespace App\Http\Requests\Facilities;

use App\Enums\FacilityCapacityUnit;
use App\Enums\FacilityRateBasis;
use App\Enums\FacilitySharing;
use App\Models\ChargeItem;
use App\Models\Facility;
use App\Models\Property;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add or change a facility in the facility Sheet, or turn one on or off from the row menu. */
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
            'sharing' => ['required', Rule::enum(FacilitySharing::class)],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'capacity_unit' => ['required', Rule::enum(FacilityCapacityUnit::class)],
            'charge_by_hand' => ['boolean'],
            'default_amount' => ['exclude_if:charge_by_hand,true', 'required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'rate_basis' => ['required', Rule::enum(FacilityRateBasis::class)],
            'active' => ['boolean'],
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
     * @return array{name: string, sharing: string, capacity: int, capacity_unit: string, default_amount: ?string, rate_basis: string, active: bool}
     */
    public function facilityAttributes(): array
    {
        $amount = $this->validated('default_amount');

        return [
            'name' => (string) $this->validated('name'),
            'sharing' => (string) $this->validated('sharing'),
            'capacity' => (int) $this->validated('capacity'),
            'capacity_unit' => (string) $this->validated('capacity_unit'),
            'default_amount' => $amount === null ? null : (string) $amount,
            'rate_basis' => (string) $this->validated('rate_basis'),
            'active' => (bool) $this->validated('active'),
        ];
    }
}
