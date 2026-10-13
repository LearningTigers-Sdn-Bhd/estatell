<?php

namespace App\Http\Requests\Reservations;

use App\Data\Boats\BoatTripData;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class BoatTripsRequest extends FormRequest
{
    /**
     * The `can:reservations.manage` and `boats` route middleware authorize this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Each unit must belong to this reservation and this property, so a unit of another booking is a 422 and nothing is
     * saved. Only the shape of each direction is checked here; `SetBoatTrips` checks the rest.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');
        $reservation = $this->route('reservation');

        if (! $property instanceof Property || ! $reservation instanceof Reservation) {
            throw new LogicException('The property and reservation route bindings are missing.');
        }

        return [
            'units' => ['required', 'array', 'min:1'],
            'units.*.unit_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('reservation_units', 'id')
                    ->where('reservation_id', $reservation->id)
                    ->where('property_id', $property->id),
            ],
            'units.*.in' => ['sometimes', 'nullable', 'array'],
            'units.*.in.mode' => ['required_with:units.*.in', Rule::enum(BoatMode::class)],
            'units.*.in.boat_departure_id' => ['required_if:units.*.in.mode,'.BoatMode::Scheduled->value, 'nullable', 'integer'],
            'units.*.in.time' => ['nullable', 'date_format:H:i'],
            'units.*.in.note' => ['nullable', 'string', 'max:255'],
            'units.*.out' => ['sometimes', 'nullable', 'array'],
            'units.*.out.mode' => ['required_with:units.*.out', Rule::enum(BoatMode::class)],
            'units.*.out.boat_departure_id' => ['required_if:units.*.out.mode,'.BoatMode::Scheduled->value, 'nullable', 'integer'],
            'units.*.out.time' => ['nullable', 'date_format:H:i'],
            'units.*.out.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The changes keyed by unit id. A direction is in the array only when the request sent it: a trip sets it, null clears
     * it, and an absent direction is left alone.
     *
     * @return array<int, array{in?: BoatTripData|null, out?: BoatTripData|null}>
     */
    public function changes(): array
    {
        $changes = [];

        foreach ($this->validated('units') as $row) {
            $change = [];

            foreach (BoatDirection::cases() as $direction) {
                if (! array_key_exists($direction->value, $row)) {
                    continue;
                }

                $change[$direction->value] = is_array($row[$direction->value])
                    ? BoatTripData::fromInput($row[$direction->value], $direction)
                    : null;
            }

            $changes[(int) $row['unit_id']] = $change;
        }

        return $changes;
    }
}
