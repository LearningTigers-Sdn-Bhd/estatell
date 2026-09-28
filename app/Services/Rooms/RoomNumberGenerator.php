<?php

namespace App\Services\Rooms;

/**
 * Turns generator input into room numbers. Stateless: it never reads or writes the database.
 *
 * Range mode: `from` and `to` are digit strings ("101", "110"). Floors mode: floors are integers and
 * `room_from` / `room_to` are digit strings; the number is the floor followed by the padded room part.
 * Both modes take an optional `prefix` and `suffix`.
 *
 * @phpstan-type Plan list<array{number: string, group: string|null}>
 */
class RoomNumberGenerator
{
    public const MAX_ROOMS = 200;

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

    private function span(int $from, int $to): int
    {
        return max(0, $to - $from + 1);
    }
}
