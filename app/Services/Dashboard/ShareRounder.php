<?php

namespace App\Services\Dashboard;

/**
 * Whole-percent shares that add up to 100, by the largest remainder method.
 */
class ShareRounder
{
    /**
     * @param  list<int>  $counts
     * @return list<int> One share for each count, in the same order. All zero when the counts add up to zero.
     */
    public function percentages(array $counts): array
    {
        $total = array_sum($counts);

        if ($total <= 0) {
            return array_fill(0, count($counts), 0);
        }

        $shares = [];
        $remainders = [];

        foreach ($counts as $index => $count) {
            $shares[$index] = intdiv($count * 100, $total);
            $remainders[$index] = ($count * 100) % $total;
        }

        $left = 100 - array_sum($shares);
        $order = array_keys($counts);
        usort($order, fn (int $a, int $b): int => [$remainders[$b], $counts[$b], $a] <=> [$remainders[$a], $counts[$a], $b]);

        foreach (array_slice($order, 0, $left) as $index) {
            $shares[$index]++;
        }

        return array_values($shares);
    }
}
