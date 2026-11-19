<?php

namespace App\Http\Requests\Concierge;

use App\Models\Property;
use App\Services\Property\PropertyToday;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A walk-in's search (adults and nights, from tonight) and, when booking, the room type picked. The arrival is always
 * the property's business date, so a guest cannot book another day from the lobby page.
 */
class WalkInRequest extends FormRequest
{
    use ReadsWalkInSearch;

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
            $this->mergeIfMissing(['adults' => 2, 'nights' => 1, 'stay_type' => 'overnight', 'hours' => 2]);

            $property = $this->route('property');

            if ($this->isDayUse() && $property instanceof Property) {
                $this->mergeIfMissing(['start_time' => $this->currentHalfHour($property)]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->searchRules(roomTypeRequired: $this->isMethod('post'));
    }

    /**
     * The half hour running now in the property's time zone (15:00 at 15:02), so a day use search opens on "now".
     */
    private function currentHalfHour(Property $property): string
    {
        $now = app(PropertyToday::class)->now($property);
        $minutes = intdiv($now->hour * 60 + $now->minute, 30) * 30;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
