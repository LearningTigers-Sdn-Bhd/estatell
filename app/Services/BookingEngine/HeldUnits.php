<?php

namespace App\Services\BookingEngine;

use App\Models\BookingHold;
use Carbon\CarbonImmutable;

/**
 * How many units open holds keep on each night, in one query. One hold line is one room or bed. It reads only.
 * See docs/29-booking-engine/03-checkout-and-hold.md#holds-and-availability.
 */
class HeldUnits
{
    /**
     * @param  list<int>  $roomTypeIds
     * @return array<int, array<string, int>> Held units by date, by room type id, between the two nights, both included.
     */
    public function byNight(int $propertyId, array $roomTypeIds, CarbonImmutable $firstNight, CarbonImmutable $lastNight): array
    {
        $rows = BookingHold::query()
            ->open()
            ->join('booking_hold_lines', 'booking_hold_lines.booking_hold_id', '=', 'booking_holds.id')
            ->where('booking_holds.property_id', $propertyId)
            ->whereIn('booking_hold_lines.room_type_id', $roomTypeIds)
            ->where('booking_holds.arrival_date', '<=', $lastNight->toDateString())
            ->where('booking_holds.departure_date', '>', $firstNight->toDateString())
            ->groupBy('booking_hold_lines.room_type_id', 'booking_holds.arrival_date', 'booking_holds.departure_date')
            ->selectRaw('booking_hold_lines.room_type_id as room_type_id, booking_holds.arrival_date as arrival, booking_holds.departure_date as departure, count(*) as held')
            ->toBase()
            ->get();
        $held = [];

        foreach ($rows as $row) {
            $from = CarbonImmutable::parse((string) $row->arrival)->max($firstNight);
            $to = CarbonImmutable::parse((string) $row->departure)->subDay()->min($lastNight);

            for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                $key = $date->toDateString();
                $held[(int) $row->room_type_id][$key] = ($held[(int) $row->room_type_id][$key] ?? 0) + (int) $row->held;
            }
        }

        return $held;
    }
}
