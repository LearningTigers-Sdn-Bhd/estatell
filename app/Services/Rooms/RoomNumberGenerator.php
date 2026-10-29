<?php

namespace App\Services\Rooms;

/**
 * Turns generator input into room numbers. Stateless: it never reads or writes the database.
 *
 * Range mode: `from` and `to` are digit strings ("101", "110"). Floors mode: floors are integers and
 * `room_from` / `room_to` are digit strings; the number is the floor followed by the padded room part.
 * Both modes take an optional `prefix` and `suffix`. List mode (`parseList`) reads a typed list such as "202-216, 218-234".
 *
 * @phpstan-type Plan list<array{number: string, group: string|null}>
 */
class RoomNumberGenerator
{
    public const MAX_ROOMS = 200;

    /** Matches the `number` rule of `RoomRequest`. */
    public const MAX_NUMBER_LENGTH = 30;

    /** Matches the `from` and `to` rules of `GenerateRoomsRequest`. */
    public const MAX_RANGE_DIGITS = 6;

    public const MODE_RANGE = 'range';

    public const MODE_FLOORS = 'floors';

    /**
     * How many rooms the input would create. Cheap, so callers can check the limit before generating.
     *
     * @param  array<string, mixed>  $input  Validated generator input.
     */
    public function size(string $mode, array $input): int
    {
        if ($mode === self::MODE_FLOORS) {
            return $this->span((int) $input['floor_from'], (int) $input['floor_to'])
                * $this->span((int) $input['room_from'], (int) $input['room_to']);
        }

        return $this->span((int) $input['from'], (int) $input['to']);
    }

    /**
     * @param  array<string, mixed>  $input  Validated generator input.
     * @return Plan
     */
    public function generate(string $mode, array $input): array
    {
        $prefix = (string) ($input['prefix'] ?? '');
        $suffix = (string) ($input['suffix'] ?? '');
        $plan = [];

        if ($mode === self::MODE_FLOORS) {
            $width = strlen((string) $input['room_from']);

            foreach (range((int) $input['floor_from'], (int) $input['floor_to']) as $floor) {
                foreach (range((int) $input['room_from'], (int) $input['room_to']) as $room) {
                    $plan[] = [
                        'number' => $prefix.$floor.str_pad((string) $room, $width, '0', STR_PAD_LEFT).$suffix,
                        'group' => 'Floor '.$floor,
                    ];
                }
            }

            return $plan;
        }

        $width = strlen((string) $input['from']);

        foreach (range((int) $input['from'], (int) $input['to']) as $value) {
            $plan[] = [
                'number' => $prefix.str_pad((string) $value, $width, '0', STR_PAD_LEFT).$suffix,
                'group' => null,
            ];
        }

        return $plan;
    }

    /**
     * List mode: parts split by commas, each one label ("Villa 3") or a range ("101-110", "G1-G4", "G1-4",
     * "01-08"). A range keeps its prefix and the zero padding of its from number. The numbers keep the typed
     * order. Returns the numbers, or the first error and the part that caused it.
     *
     * @return array{numbers: list<string>, error: string|null, part: string|null}
     */
    public function parseList(string $list): array
    {
        $parts = array_values(array_filter(array_map(trim(...), explode(',', $list)), fn (string $part): bool => $part !== ''));
        $runs = [];
        $size = 0;

        foreach ($parts as $part) {
            if (! preg_match('/^(\D*?)(\d+)\s*-\s*(\D*?)(\d+)$/u', $part, $match)) {
                if (mb_strlen($part) > self::MAX_NUMBER_LENGTH) {
                    return $this->listError(__('":part" is too long. A room number takes at most :max characters.', ['part' => $part, 'max' => self::MAX_NUMBER_LENGTH]), $part);
                }

                $runs[] = ['label' => $part];
                $size++;

                continue;
            }

            [, $prefix, $from, $toPrefix, $to] = $match;

            if (trim($toPrefix) !== '' && mb_strtolower(trim($toPrefix)) !== mb_strtolower(trim($prefix))) {
                return $this->listError(__('":part" has two different prefixes. Use one, like G1-G4.', ['part' => $part]), $part);
            }

            if (strlen($from) > self::MAX_RANGE_DIGITS || strlen($to) > self::MAX_RANGE_DIGITS) {
                return $this->listError(__('":part" has a number longer than :max digits.', ['part' => $part, 'max' => self::MAX_RANGE_DIGITS]), $part);
            }

            if ((int) $to < (int) $from) {
                return $this->listError(__('":part" runs backwards. Put the smaller number first.', ['part' => $part]), $part);
            }

            if (mb_strlen($prefix) + max(strlen($from), strlen($to)) > self::MAX_NUMBER_LENGTH) {
                return $this->listError(__('":part" is too long. A room number takes at most :max characters.', ['part' => $part, 'max' => self::MAX_NUMBER_LENGTH]), $part);
            }

            $runs[] = ['prefix' => $prefix, 'from' => $from, 'to' => $to];
            $size += $this->span((int) $from, (int) $to);
        }

        if ($size > self::MAX_ROOMS) {
            return $this->listError(__('That is :count rooms. One group takes at most :max.', ['count' => $size, 'max' => self::MAX_ROOMS]), null);
        }

        $numbers = [];
        $seen = [];

        foreach ($runs as $run) {
            $expanded = isset($run['label'])
                ? [$run['label']]
                : array_map(
                    fn (int $value): string => $run['prefix'].str_pad((string) $value, strlen($run['from']), '0', STR_PAD_LEFT),
                    range((int) $run['from'], (int) $run['to']),
                );

            foreach ($expanded as $number) {
                $key = mb_strtolower($number);

                if (isset($seen[$key])) {
                    return $this->listError(__(':number is listed twice. Each number can be used once.', ['number' => $number]), $number);
                }

                $seen[$key] = true;
                $numbers[] = $number;
            }
        }

        return ['numbers' => $numbers, 'error' => null, 'part' => null];
    }

    /**
     * @return array{numbers: list<string>, error: string, part: string|null}
     */
    private function listError(string $error, ?string $part): array
    {
        return ['numbers' => [], 'error' => $error, 'part' => $part];
    }

    private function span(int $from, int $to): int
    {
        return max(0, $to - $from + 1);
    }
}
