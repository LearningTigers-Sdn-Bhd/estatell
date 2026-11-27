<?php

namespace App\Services\DoorLocks;

use App\Models\DoorCard;
use App\Models\DoorLock;
use App\Models\DoorPasscode;
use Carbon\CarbonImmutable;

/**
 * The access log of one TTLock lock: who opened it, failed tries with a wrong or expired PIN or card, and alarms. Each event that used
 * a PIN or card we issued is named by that PIN's or card's label. Reads TTLock only; saves nothing.
 *
 * ponytail: the record types are the Open API `recordType` codes (not the lock's own `recordTypeFromLock`). A failed try comes as the
 * same type with `success = 0`. Types not listed show as "Lock event N"; add them as real locks show them.
 */
class DoorLockLog
{
    /**
     * @var array<int, array{0: string, 1: 'opened'|'failed'|'alarm'|'other'}>
     */
    private const TYPES = [
        1 => ['Opened with the app', 'opened'],
        4 => ['Opened with a PIN', 'opened'],
        7 => ['Opened with a card', 'opened'],
        8 => ['Opened with a fingerprint', 'opened'],
        9 => ['Opened with a wristband', 'opened'],
        10 => ['Opened with the key', 'opened'],
        11 => ['Locked with the app', 'other'],
        12 => ['Opened remotely', 'opened'],
        29 => ['Forced open', 'alarm'],
        30 => ['Door closed', 'other'],
        31 => ['Door opened', 'other'],
        32 => ['Opened from inside', 'opened'],
        33 => ['Locked with a fingerprint', 'other'],
        34 => ['Locked with a PIN', 'other'],
        35 => ['Locked with a card', 'other'],
        36 => ['Locked with the key', 'other'],
        37 => ['Opened with the remote', 'opened'],
        44 => ['Tamper alarm', 'alarm'],
        45 => ['Locked automatically', 'other'],
        46 => ['Opened with the unlock key', 'opened'],
        47 => ['Locked with the lock key', 'other'],
        48 => ['Keypad locked after too many wrong tries', 'alarm'],
        49 => ['Opened with a hotel card', 'opened'],
        50 => ['Opened by high temperature', 'alarm'],
        51 => ['Deleted card tried', 'failed'],
        55 => ['Opened with a key fob', 'opened'],
        57 => ['Opened with a QR code', 'opened'],
        58 => ['Expired QR code', 'failed'],
        63 => ['Opened by passage mode', 'opened'],
        64 => ['Door left open', 'alarm'],
        65 => ['Failed to open', 'failed'],
        66 => ['Failed to lock', 'failed'],
        67 => ['Opened with face', 'opened'],
        68 => ['Face refused: locked from inside', 'failed'],
        71 => ['Face refused: expired', 'failed'],
        75 => ['Opened with app access', 'opened'],
        76 => ['Opened with remote access', 'opened'],
    ];

    /**
     * The label of an opening TTLock marks as not successful, so the log never reads "Opened" for a door that stayed shut.
     *
     * @var array<int, string>
     */
    private const REFUSED = [
        1 => 'App unlock failed',
        4 => 'PIN refused',
        7 => 'Card refused',
        8 => 'Fingerprint refused',
        12 => 'Remote unlock failed',
        49 => 'Card refused',
        55 => 'Key fob refused',
        67 => 'Face refused',
    ];

    public function __construct(private TtlockApi $api) {}

    /**
     * The record types that carry a PIN or a card number in `keyboardPwd`.
     */
    private const PIN_TYPES = [4, 34];

    private const CARD_TYPES = [7, 35, 49, 51];

    /**
     * The events between two moments, newest first, up to 500. `who` is the booking code (or the PIN or card label, or the
     * TTLock user) and `guest` the booker's name under it. A PIN or card not in our database (issued in the TTLock app, or from
     * another estatell environment on the same TTLock account) shows the name TTLock gives it; TTLock masks an unknown PIN as
     * `699***`, which shows as "Unknown PIN". `method` says whether `code` is a PIN or a card number.
     *
     * @return list<array{at: string, kind: string, event: string, who: string|null, guest: string|null, method: 'pin'|'card'|null, code: string|null}>
     */
    public function for(DoorLock $lock, string $accessToken, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $records = $this->api->lockRecords($accessToken, $lock->ttlock_lock_id, $from, $to);

        // Codes are encrypted, so PINs are matched here, not in SQL. PINs and cards that ended up to 60 days before the window are
        // included, so an expired PIN a guest still types is named too. Oldest first, so a reused code maps to its newest stay.
        $pins = DoorPasscode::query()->with('reservationUnit.reservation.booker')->where('door_lock_id', $lock->id)->whereNotNull('code')
            ->where('ends_at', '>=', $from->subDays(60))->where('starts_at', '<=', $to)->oldest('starts_at')->get()
            ->mapWithKeys(fn (DoorPasscode $passcode): array => [(string) $passcode->code => $passcode])->all();
        $cards = DoorCard::query()->with('reservationUnit.reservation.booker')->where('door_lock_id', $lock->id)
            ->where('ends_at', '>=', $from->subDays(60))->where('starts_at', '<=', $to)->oldest('starts_at')->get()
            ->mapWithKeys(fn (DoorCard $card): array => [(string) $card->card_number => $card])->all();

        $events = array_map(function (array $record) use ($pins, $cards): array {
            [$event, $kind] = self::TYPES[$record['type']] ?? ['Lock event '.$record['type'], 'other'];

            if (! $record['success'] && $kind === 'opened') {
                [$event, $kind] = [self::REFUSED[$record['type']] ?? 'Failed to open', 'failed'];
            }

            $method = match (true) {
                in_array($record['type'], self::PIN_TYPES, true) => 'pin',
                in_array($record['type'], self::CARD_TYPES, true) => 'card',
                default => null,
            };
            $code = $method === null ? null : $record['code'];
            $issued = $code === null ? null : ($method === 'pin' ? $pins : $cards)[$code] ?? null;
            $reservation = $issued?->reservationUnit?->reservation;
            // A name we sent as "{booking code} - {booker}" (StayLockLabel) splits back into its two lines.
            $named = $code !== null && $record['username'] !== null && ! str_contains($record['username'], '*')
                ? explode(' - ', $record['username'], 2) + [1 => null] : null;

            return [
                'at' => $record['at']->toIso8601String(),
                'kind' => $kind,
                'event' => __($event),
                'who' => match (true) {
                    $reservation !== null => $reservation->short_code,
                    $issued !== null => $issued->label,
                    $named !== null => $named[0],
                    $code !== null => $method === 'pin' ? __('Unknown PIN') : __('Unknown card'),
                    default => $record['username'],
                },
                'guest' => $reservation !== null ? $reservation->booker?->full_name : ($issued === null ? $named[1] ?? null : null),
                'method' => $method,
                'code' => $code,
            ];
        }, $records);

        usort($events, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return $events;
    }
}
