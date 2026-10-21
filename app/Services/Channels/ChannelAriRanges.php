<?php

namespace App\Services\Channels;

use Carbon\CarbonImmutable;

/**
 * Turns per-date values into the fewest date ranges, and joins overlapping or touching ranges. Pure functions.
 */
class ChannelAriRanges
{
    /**
     * Consecutive dates with equal values become one range.
     *
     * @param  array<string, array<string, mixed>>  $valuesByDate  Keyed by `Y-m-d`
     * @return list<array{from: string, to: string, values: array<string, mixed>}>
     */
    public function compress(array $valuesByDate): array
    {
        ksort($valuesByDate);
        $ranges = [];

        foreach ($valuesByDate as $date => $values) {
            $last = array_key_last($ranges);

            if ($last !== null
                && $ranges[$last]['values'] === $values
                && CarbonImmutable::parse($ranges[$last]['to'])->addDay()->toDateString() === $date) {
                $ranges[$last]['to'] = $date;

                continue;
            }

            $ranges[] = ['from' => $date, 'to' => $date, 'values' => $values];
        }

        return $ranges;
    }

    /**
     * Join ranges that overlap or touch.
     *
     * @param  array<int, array{0: string, 1: string}>  $ranges  `[from, to]` pairs of `Y-m-d`
     * @return list<array{0: string, 1: string}>
     */
    public function merge(array $ranges): array
    {
        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];

        foreach ($ranges as [$from, $to]) {
            $last = array_key_last($merged);

            if ($last !== null && $from <= CarbonImmutable::parse($merged[$last][1])->addDay()->toDateString()) {
                $merged[$last][1] = max($merged[$last][1], $to);

                continue;
            }

            $merged[] = [$from, $to];
        }

        return $merged;
    }
}
