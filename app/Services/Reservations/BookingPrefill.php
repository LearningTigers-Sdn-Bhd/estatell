<?php

namespace App\Services\Reservations;

use App\Enums\SellUnit;
use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * What the New booking form starts with when it opens from an empty Timeline cell: `arrival`, `room` and `bed` in the
 * query. Each part is checked alone. A bad or foreign value is ignored and that part stays blank; the other parts still
 * apply. A bed needs no room. A room that sells beds is not taken as `room`. See docs/18-front-desk/07-timeline-editing.md.
 */
class BookingPrefill
{
    /**
     * The longest wait for an arrival the booking form offers, in days.
     */
    private const WINDOW_DAYS = 1095;

    /**
     * @param  array<string, mixed>  $query
     * @return array{arrival: string|null, roomTypeId: int|null, roomId: int|null, bedId: int|null}
     */
    public function fromQuery(Property $property, array $query, CarbonImmutable $businessDate): array
    {
        $bed = $this->bed($property, $query['bed'] ?? null);
        $room = $bed === null ? $this->room($property, $query['room'] ?? null) : null;

        return [
            'arrival' => $this->arrival($query['arrival'] ?? null, $businessDate),
            'roomTypeId' => $bed?->room->room_type_id ?? $room?->room_type_id,
            'roomId' => $room?->id,
            'bedId' => $bed?->id,
        ];
    }

    private function arrival(mixed $value, CarbonImmutable $businessDate): ?string
    {
        if (! is_string($value) || Validator::make(['arrival' => $value], ['arrival' => ['date_format:Y-m-d']])->fails()) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $businessDate->getTimezone());

        return $date->lt($businessDate->startOfDay()) || $date->gt($businessDate->startOfDay()->addDays(self::WINDOW_DAYS)) ? null : $value;
    }

    private function room(Property $property, mixed $value): ?Room
    {
        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        return Room::query()
            ->where('property_id', $property->id)
            ->active()
            ->whereHas('roomType', fn ($query) => $query->active()->where('sell_unit', SellUnit::Room))
            ->find((int) $value);
    }

    private function bed(Property $property, mixed $value): ?Bed
    {
        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        return Bed::query()
            ->active()
            ->whereHas('room', fn ($query) => $query
                ->where('property_id', $property->id)
                ->active()
                ->whereHas('roomType', fn ($types) => $types->active()->where('sell_unit', SellUnit::Bed)))
            ->with('room:id,room_type_id')
            ->find((int) $value);
    }
}
