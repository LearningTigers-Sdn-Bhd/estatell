<?php

namespace App\Http\Requests\Reports;

use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * The Boats and crew panel of the Boat manifest: the main sailing of each departure on one date. Whether a boat or crew
 * member can be picked is the Action's refusal, not a rule here. The route checks `boats` and `reservations.manage`.
 */
class SaveBoatManifestSailingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sailings' => array_map(fn (mixed $sailing): mixed => is_array($sailing) ? [
                ...$sailing,
                'boat_id' => filled($sailing['boat_id'] ?? null) ? $sailing['boat_id'] : null,
                'in_charge_id' => filled($sailing['in_charge_id'] ?? null) ? $sailing['in_charge_id'] : null,
                'assistant_ids' => array_values(array_filter((array) ($sailing['assistant_ids'] ?? []), filled(...))),
            ] : $sailing, (array) $this->input('sailings', [])),
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

        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'sailings' => ['required', 'array', 'max:50'],
            'sailings.*.boat_departure_id' => ['required', 'integer', Rule::exists('boat_departures', 'id')->where('property_id', $property->id)],
            'sailings.*.boat_id' => ['nullable', 'integer'],
            'sailings.*.in_charge_id' => ['nullable', 'integer'],
            'sailings.*.assistant_ids' => ['present', 'array'],
            'sailings.*.assistant_ids.*' => ['integer'],
            'sailings.*.reset_to_default' => ['sometimes', 'boolean'],
        ];
    }
}
