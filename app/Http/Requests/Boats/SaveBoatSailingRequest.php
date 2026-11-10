<?php

namespace App\Http\Requests\Boats;

use App\Data\Boats\SailingData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Change the boat and crew of a sailing, or add a boat to a departure, on the Boats board. Whether the departure or sailing
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
            'boat_departure_id' => ['required', 'integer'],
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
            assistantIds: array_map(intval(...), $this->validated('assistant_ids')),
        );
    }
}
