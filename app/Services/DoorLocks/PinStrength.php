<?php

namespace App\Services\DoorLocks;

/**
 * TTLock refuses a PIN it finds too simple (`errcode` -2032, "avoid consecutive or repeated digits"). Its exact rule is not
 * published; 1234 was refused and 1122 and 135790 were accepted on a real lock. This catches the clear cases: one digit repeated
 * (1111) and a run of digits going up or down by one (1234, 9876).
 */
class PinStrength
{
    public static function isSimple(string $code): bool
    {
        $digits = array_map('intval', str_split($code));

        if (count($digits) < 2) {
            return false;
        }

        $steps = array_unique(array_map(fn (int $i): int => $digits[$i] - $digits[$i - 1], range(1, count($digits) - 1)));

        return $steps === [0] || $steps === [1] || $steps === [-1];
    }

    /**
     * A random PIN of `$length` digits that is not simple and does not start with 0.
     */
    public static function random(int $length): string
    {
        do {
            $code = (string) random_int(10 ** ($length - 1), 10 ** $length - 1);
        } while (self::isSimple($code));

        return $code;
    }
}
