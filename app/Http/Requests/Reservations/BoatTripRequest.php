<?php

namespace App\Http\Requests\Reservations;

use App\Data\Boats\BoatTripData;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BoatTripRequest extends FormRequest
{
    /**
     * The `can:reservations.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. `SetBoatTrip` checks the unit, the departure and the time, and refuses with a code.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(BoatMode::class)],
            'boat_departure_id' => ['required_if:mode,'.BoatMode::Scheduled->value, 'integer'],
            'time' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The trip as staff set it. A scheduled trip keeps no time, and a charter or own boat trip keeps no departure.
     */
    public function toTripData(BoatDirection $direction): BoatTripData
    {
        $mode = BoatMode::from((string) $this->validated('mode'));
        $scheduled = $mode->needsDeparture();

        return new BoatTripData(
            direction: $direction,
            mode: $mode,
            boatDepartureId: $scheduled && $this->filled('boat_departure_id') ? $this->integer('boat_departure_id') : null,
            time: $scheduled || ! $this->filled('time') ? null : $this->string('time')->toString(),
            note: $this->filled('note') ? $this->string('note')->toString() : null,
        );
    }
}
