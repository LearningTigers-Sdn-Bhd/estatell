<?php

namespace App\Http\Requests\Boats;

use App\Data\Boats\SailingData;
use App\Enums\BoatDirection;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change the boat, crew or time of a sailing, add a boat to a departure, or add an extra trip (a `direction` and no departure),
 * on the Boats board. Whether the departure or sailing
 * belongs to the property is a 404 in the controller. Whether a boat or crew member can be picked is the Action's refusal.
 * The route checks `boats` and `reservations.manage`.
 */
class SaveBoatSailingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'boat_id' => filled($this->input('boat_id')) ? $this->input('boat_id') : null,
            'direction' => filled($this->input('direction')) ? $this->input('direction') : null,
            'leaves_at' => filled($this->input('leaves_at')) ? $this->input('leaves_at') : null,
            'arrives_at' => filled($this->input('arrives_at')) ? $this->input('arrives_at') : null,
            'in_charge_id' => filled($this->input('in_charge_id')) ? $this->input('in_charge_id') : null,
            'assistant_ids' => array_values(array_filter((array) $this->input('assistant_ids', []), filled(...))),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'boat_departure_id' => ['nullable', 'integer', 'required_without:direction'],
            'direction' => ['nullable', Rule::enum(BoatDirection::class)],
            'pickup_point' => ['nullable', 'string', 'max:255'],
            'sets_times' => ['nullable', 'boolean'],
            'leaves_at' => ['nullable', 'date_format:H:i', 'required_with:direction'],
            'arrives_at' => ['nullable', 'date_format:H:i', 'required_with:direction'],
            'boat_sailing_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'boat_id' => ['nullable', 'integer'],
            'in_charge_id' => ['nullable', 'integer'],
            'assistant_ids' => ['present', 'array', 'max:20'],
            'assistant_ids.*' => ['integer'],
        ];
    }

    public function sailingDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', (string) $this->validated('date'))->startOfDay();
    }

    public function sailingData(): SailingData
    {
        return new SailingData(
            boatId: $this->validated('boat_id') === null ? null : (int) $this->validated('boat_id'),
            inChargeId: $this->validated('in_charge_id') === null ? null : (int) $this->validated('in_charge_id'),
            assistantIds: array_values(array_map(intval(...), $this->validated('assistant_ids'))),
            setsTimes: $this->boolean('sets_times') || $this->validated('direction') !== null,
            leavesAt: $this->validated('leaves_at'),
            arrivesAt: $this->validated('arrives_at'),
            direction: $this->validated('direction') === null ? null : BoatDirection::from($this->validated('direction')),
            pickupPoint: $this->validated('pickup_point'),
        );
    }
}
