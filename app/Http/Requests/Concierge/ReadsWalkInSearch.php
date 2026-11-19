<?php

namespace App\Http\Requests\Concierge;

use App\Data\Kiosk\KioskWalkInData;
use App\Enums\StayType;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Validation\Rule;

/**
 * The room a Concierge walk-in picked: how many adults, how long, from tonight, and the room type. The arrival is always the
 * property's business date, so a guest cannot book another day from the lobby page. Shared by every request that carries the
 * choice (the search, the details page, the booking and the returning-guest check), so they all read it the same way.
 */
trait ReadsWalkInSearch
{
    public const int MAX_NIGHTS = 14;

    public const int MAX_ADULTS = 8;

    public const int MAX_HOURS = 12;

    /**
     * @return array<string, mixed>
     */
    protected function searchRules(bool $roomTypeRequired): array
    {
        return [
            'adults' => ['required', 'integer', 'min:1', 'max:'.self::MAX_ADULTS],
            'stay_type' => ['sometimes', Rule::enum(StayType::class)],
            'nights' => [Rule::requiredIf(! $this->isDayUse()), 'nullable', 'integer', 'min:1', 'max:'.self::MAX_NIGHTS],
            'start_time' => [Rule::requiredIf($this->isDayUse()), 'nullable', 'date_format:H:i'],
            'hours' => [Rule::requiredIf($this->isDayUse()), 'nullable', 'integer', 'min:1', 'max:'.self::MAX_HOURS],
            'room_type_id' => [Rule::requiredIf($roomTypeRequired), 'integer'],
        ];
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
