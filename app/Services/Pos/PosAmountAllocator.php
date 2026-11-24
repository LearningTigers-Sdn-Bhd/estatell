<?php

namespace App\Services\Pos;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Splits an amount over parts in whole sen, in proportion to the parts, so the shares always add up to the amount exactly.
 * Each part first gets the floor of its exact share, then the sen left over go to the parts with the biggest fractions, ties
 * to the earlier part. A room charge for part of a bill uses it to post a matching part of every net, service charge and tax
 * bucket, and a partial refund uses it to give back a matching part of every bucket. It reads nothing.
 */
class PosAmountAllocator
{
    /**
     * @param  list<string>  $parts  Non-negative amounts.
     * @return list<string> One share for each part, in the same order.
     */
    public function allocate(string $amount, array $parts): array
    {
        $total = array_reduce($parts, fn (BigDecimal $sum, string $part): BigDecimal => $sum->plus($part), BigDecimal::zero());
        $amount = BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);

        if (! $total->isPositive() || ! $amount->isPositive()) {
            return array_map(fn (): string => '0.00', $parts);
        }

        $floors = [];
        $fractions = [];

        foreach ($parts as $index => $part) {
            $exact = $amount->multipliedBy($part)->dividedBy($total, 12, RoundingMode::HalfUp);
            $floors[$index] = $exact->toScale(2, RoundingMode::Down);
            $fractions[$index] = $exact->minus($floors[$index]);
        }

        $left = $amount->minus(array_reduce($floors, fn (BigDecimal $sum, BigDecimal $floor): BigDecimal => $sum->plus($floor), BigDecimal::zero()))->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();

        $order = array_keys($fractions);
        usort($order, fn (int $a, int $b): int => $fractions[$b]->compareTo($fractions[$a]) ?: $a <=> $b);

        foreach (array_slice($order, 0, max(0, $left)) as $index) {
            $floors[$index] = $floors[$index]->plus('0.01');
        }

        ksort($floors);

        return array_values(array_map(fn (BigDecimal $share): string => $share->toScale(2)->__toString(), $floors));
    }
}
