<?php

namespace App\Services\DoorLocks;

/**
 * The number TTLock knows an IC card by: the card UID bytes in reader order, read as a big-endian decimal.
 * UID 0D:D2:5C:E7 is 231890151 (checked against the TTLock app). To verify on a real lock: that the lock opens for this order.
 */
class CardNumber
{
    /**
     * @param  string  $uid  Hex, with or without `:`, `-` or spaces. Four or seven bytes (the MIFARE sizes that fit a 64-bit number).
     * @return int|null Null when the UID is not valid hex of a supported length, or is all zero.
     */
    public function fromUid(string $uid): ?int
    {
        $hex = strtoupper(preg_replace('/[\s:\-]/', '', $uid) ?? '');

        if (preg_match('/^([0-9A-F]{8}|[0-9A-F]{14})$/', $hex) !== 1) {
            return null;
        }

        $number = (int) hexdec($hex);

        return $number > 0 ? $number : null;
    }
}
