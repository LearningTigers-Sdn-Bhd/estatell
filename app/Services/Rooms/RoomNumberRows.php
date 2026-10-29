<?php

namespace App\Services\Rooms;

/**
 * Reads the numbers fields of the onboarding Room numbers table: each row through `RoomNumberGenerator::parseList`,
 * then a number already listed in an earlier row is an error on the later row. Stateless: it never touches the
 * database. The preview and the save both use it, so the save never trusts the preview.
 */
class RoomNumberRows
{
    public function __construct(private RoomNumberGenerator $generator) {}

    /**
     * One result per row, in row order. A row with a list error has no numbers. A row that repeats numbers of an
     * earlier row keeps its numbers, lists the repeats in `repeated` and has an error.
     *
     * @param  list<string>  $lists
     * @return list<array{numbers: list<string>, repeated: list<string>, error: string|null}>
     */
    public function parse(array $lists): array
    {
        $seen = [];
        $rows = [];

        foreach ($lists as $list) {
            $parsed = $this->generator->parseList($list);

            if ($parsed['error'] !== null) {
                $rows[] = ['numbers' => [], 'repeated' => [], 'error' => $parsed['error']];

                continue;
            }

            $repeated = array_values(array_filter($parsed['numbers'], fn (string $number): bool => isset($seen[mb_strtolower($number)])));

            foreach ($parsed['numbers'] as $number) {
                $seen[mb_strtolower($number)] = true;
            }

            $rows[] = [
                'numbers' => $parsed['numbers'],
                'repeated' => $repeated,
                'error' => $repeated === [] ? null : __(':number is listed twice. Each number can be used once.', ['number' => $repeated[0]]),
            ];
        }

        return $rows;
    }
}
