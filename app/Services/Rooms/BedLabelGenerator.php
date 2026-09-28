<?php

namespace App\Services\Rooms;

/**
 * Turns a prefix, a bed count and a start number into bed labels. The default prefix is the room's letter: room 1 gets A1, A2 ...,
 * room 2 gets B1, B2 ... After Z the letters continue as AA, AB. Stateless: it never touches the database.
 */
class BedLabelGenerator
{
    public const MAX_BEDS = 50;

    /**
     * The next number to use for a prefix: one above the highest number among the labels that are
     * the prefix followed by digits ("A4" with prefix "A" gives 5). Other labels are ignored.
     *
     * @param  iterable<string>  $existingLabels
     */
    public function nextNumber(string $prefix, iterable $existingLabels): int
    {
        $letter = preg_quote($prefix, '/');
        $highest = 0;

        foreach ($existingLabels as $label) {
            if (preg_match('/^'.$letter.'(\d+)$/i', $label, $match)) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        return $highest + 1;
    }

    /**
     * The letter for a zero-based room index: 0 is A, 25 is Z, 26 is AA.
     */
    public function letter(int $roomIndex): string
    {
        $letter = '';

        for ($n = $roomIndex + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + ($n - 1) % 26).$letter;
        }

        return $letter;
    }

    /**
     * `$count` labels made of the prefix and a number from `$start`: labels('A', 2, 5) is A5, A6.
     *
     * @return list<string>
     */
    public function labels(string $prefix, int $count, int $start = 1): array
    {
        if ($count < 1) {
            return [];
        }

        return array_map(fn (int $number): string => $prefix.$number, range($start, $start + $count - 1));
    }
}
