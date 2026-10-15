<?php

namespace App\Services\Property;

/**
 * The Malaysian states and federal territories offered on Basics. The property stores the name in `state_code`, which
 * is plain text and prints as written.
 */
class MalaysianStates
{
    /** @return list<string> */
    public static function names(): array
    {
        return [
            'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang', 'Perak', 'Perlis', 'Pulau Pinang',
            'Sabah', 'Sarawak', 'Selangor', 'Terengganu',
            'Kuala Lumpur', 'Labuan', 'Putrajaya',
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (string $name): array => ['value' => $name, 'label' => $name], self::names());
    }
}
