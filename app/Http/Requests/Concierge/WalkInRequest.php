<?php

namespace App\Http\Requests\Concierge;

use App\Data\Kiosk\KioskWalkInData;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A walk-in's search (adults and nights, from tonight) and, when booking, the room type picked. The arrival is always
 * the property's business date, so a guest cannot book another day from the lobby page.
 */
class WalkInRequest extends FormRequest
{
    public const int MAX_NIGHTS = 14;

    public const int MAX_ADULTS = 8;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The page opens with two adults for one night, so the first look already shows rooms and prices.
     */
    protected function prepareForValidation(): void
    {
        if ($this->isMethod('get')) {
            $this->mergeIfMissing(['adults' => 2, 'nights' => 1]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'adults' => ['required', 'integer', 'min:1', 'max:'.self::MAX_ADULTS],
            'nights' => ['required', 'integer', 'min:1', 'max:'.self::MAX_NIGHTS],
            'room_type_id' => [Rule::requiredIf($this->isMethod('post')), 'integer'],
        ];
    }

    public function search(Property $property, CurrentBusinessDate $businessDate): ?KioskWalkInData
    {
        $date = $businessDate->date($property);

        return $date === null ? null : new KioskWalkInData($date->toImmutable(), $this->integer('nights'), $this->integer('adults'));
    }
}
