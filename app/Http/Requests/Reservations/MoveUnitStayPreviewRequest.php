<?php

namespace App\Http\Requests\Reservations;

use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Timeline drop of a Confirmed bar on new dates and a room or bed: the dates and the picked row. Dates and choices
 * only, never an amount.
 */
class MoveUnitStayPreviewRequest extends FormRequest
{
    /**
     * The `can:reservations.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'arrival_date' => ['required', 'date_format:Y-m-d'],
            'departure_date' => ['required', 'date_format:Y-m-d'],
            'room_id' => ['nullable', 'integer', 'required_without:bed_id'],
            'bed_id' => ['nullable', 'integer', 'required_without:room_id'],
        ];
    }

    public function arrival(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('arrival_date')->toString())->startOfDay();
    }

    public function departure(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('departure_date')->toString())->startOfDay();
    }

    /**
     * The picked room or bed of this property, or null when it is not one of its own.
     */
    public function target(Property $property): Room|Bed|null
    {
        if ($this->filled('bed_id')) {
            return Bed::query()->whereHas('room', fn ($rooms) => $rooms->where('property_id', $property->id))->find($this->integer('bed_id'));
        }

        return Room::query()->where('property_id', $property->id)->find($this->integer('room_id'));
    }
}
