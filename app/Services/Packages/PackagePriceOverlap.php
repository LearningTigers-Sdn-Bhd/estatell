<?php

namespace App\Services\Packages;

class PackagePriceOverlap
{
    /**
     * The index of the first row whose arrival period overlaps an earlier row of the same room type, or null.
     * Both ends of a period count, so a period that starts the day another ends overlaps it.
     *
     * @param  list<array{room_type_id: int, arrival_from: string, arrival_to: string}>  $prices  Dates as `Y-m-d`.
     */
    public function firstOverlap(array $prices): ?int
    {
        foreach ($prices as $index => $price) {
            foreach (array_slice($prices, 0, $index) as $earlier) {
                if (
                    $earlier['room_type_id'] === $price['room_type_id']
                    && $price['arrival_from'] <= $earlier['arrival_to']
                    && $earlier['arrival_from'] <= $price['arrival_to']
                ) {
                    return $index;
                }
            }
        }

        return null;
    }
}
