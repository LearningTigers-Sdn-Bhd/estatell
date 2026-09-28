<?php

namespace App\Services\Rooms;

use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * Finds which room numbers an active room of the property already uses. Read only.
 */
class RoomNumberAvailability
{
    /**
     * Returns the given numbers that are taken, in the given order. Comparison ignores case.
     *
     * @param  list<string>  $numbers
     * @return list<string>
     */
    public function taken(Property $property, array $numbers): array
    {
        if ($numbers === []) {
            return [];
        }

        $used = $property->rooms()->active()
            ->whereIn(DB::raw('lower(number)'), array_map('mb_strtolower', $numbers))
            ->pluck('number')
            ->map(fn (string $number): string => mb_strtolower($number))
            ->all();

        return array_values(array_filter($numbers, fn (string $number): bool => in_array(mb_strtolower($number), $used, true)));
    }
}
