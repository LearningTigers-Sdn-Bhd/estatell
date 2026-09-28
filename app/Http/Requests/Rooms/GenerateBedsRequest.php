<?php

namespace App\Http\Requests\Rooms;

use App\Enums\SellUnit;
use App\Models\Property;
use App\Models\Room;
use App\Services\Rooms\BedLabelGenerator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateBedsRequest extends FormRequest
{
    /**
     * The `can:rooms.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['prefix' => filled($this->input('prefix')) ? trim((string) $this->input('prefix')) : null]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');

        return [
            'count' => ['required', 'integer', 'min:1', 'max:'.BedLabelGenerator::MAX_BEDS],
            'prefix' => ['nullable', 'string', 'max:10'],
            'room_ids' => ['nullable', 'array', 'min:1'],
            'room_ids.*' => [
                'integer', 'distinct',
                Rule::exists(Room::class, 'id')
                    ->where('property_id', $property->id)
                    ->whereNull('archived_at')
                    ->whereIn('room_type_id', fn ($query) => $query->select('id')->from('room_types')->where('sell_unit', SellUnit::Bed->value)),
            ],
        ];
    }
}
