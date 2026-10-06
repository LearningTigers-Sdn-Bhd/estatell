<?php

namespace App\Http\Requests\Reservations;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Move room form's preview: the first night to move and, optionally, the room type to look at.
 */
class MoveUnitPreviewRequest extends FormRequest
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
            'from_night' => ['required', 'date_format:Y-m-d'],
            'room_type_id' => ['nullable', 'integer'],
        ];
    }

    public function fromNight(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('from_night')->toString())->startOfDay();
    }

    public function roomTypeId(): ?int
    {
        return $this->filled('room_type_id') ? $this->integer('room_type_id') : null;
    }
}
