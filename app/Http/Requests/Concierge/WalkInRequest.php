<?php

namespace App\Http\Requests\Concierge;

use App\Data\Kiosk\KioskWalkInData;
use App\Enums\StayType;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
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

    public const int MAX_HOURS = 12;

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
        return [
            'adults' => ['required', 'integer', 'min:1', 'max:'.self::MAX_ADULTS],
            'stay_type' => ['sometimes', Rule::enum(StayType::class)],
            'nights' => [Rule::requiredIf(! $this->isDayUse()), 'nullable', 'integer', 'min:1', 'max:'.self::MAX_NIGHTS],
            'start_time' => [Rule::requiredIf($this->isDayUse()), 'nullable', 'date_format:H:i'],
            'hours' => [Rule::requiredIf($this->isDayUse()), 'nullable', 'integer', 'min:1', 'max:'.self::MAX_HOURS],
            'room_type_id' => [Rule::requiredIf($this->isMethod('post')), 'integer'],
        ];
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

    public function isDayUse(): bool
    {
        return $this->input('stay_type') === StayType::DayUse->value;
    }

    public function search(Property $property, CurrentBusinessDate $businessDate): ?KioskWalkInData
    {
        $date = $businessDate->date($property);

        if ($date === null) {
            return null;
        }

        return $this->isDayUse()
            ? new KioskWalkInData($date->toImmutable(), 0, $this->integer('adults'), startTime: $this->string('start_time')->toString(), hours: $this->integer('hours'))
            : new KioskWalkInData($date->toImmutable(), $this->integer('nights'), $this->integer('adults'));
    }
}
