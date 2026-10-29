<?php

namespace App\Services\Rooms;

/**
 * Joins room numbers into the shortest list of ranges ("102-134, 140"), the reverse of
 * `RoomNumberGenerator::parseList`, so a saved group reads back as typed. Stateless: it never touches the database.
 */
class RoomNumberRanges
{
    /**
     * Numbers next to each other in the list join when they share a prefix and the second is the first plus one
     * at the same zero padding. A range repeats its prefix on both ends ("G1-G4"). Other numbers stay as they are.
     *
     * @param  list<string>  $numbers  In room order.
     */
    public function join(array $numbers): string
    {
        $parts = [];
        $run = null;

        foreach ($numbers as $number) {
            $parsed = preg_match('/^(\D*)(\d{1,'.RoomNumberGenerator::MAX_RANGE_DIGITS.'})$/u', $number, $match)
                ? ['prefix' => $match[1], 'digits' => $match[2]]
                : null;

            if ($run !== null && $parsed !== null && $parsed['prefix'] === $run['prefix']
                && $parsed['digits'] === str_pad((string) ((int) $run['last'] + 1), strlen($run['first']), '0', STR_PAD_LEFT)) {
                $run['last'] = $parsed['digits'];

                continue;
            }

            if ($run !== null) {
                $parts[] = $this->format($run);
            }

            $run = $parsed === null ? null : ['prefix' => $parsed['prefix'], 'first' => $parsed['digits'], 'last' => $parsed['digits']];

            if ($run === null) {
                $parts[] = $number;
            }
        }

        if ($run !== null) {
            $parts[] = $this->format($run);
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array{prefix: string, first: string, last: string}  $run
     */
    private function format(array $run): string
    {
        $first = $run['prefix'].$run['first'];

        return $run['first'] === $run['last'] ? $first : $first.'-'.$run['prefix'].$run['last'];
    }
}
