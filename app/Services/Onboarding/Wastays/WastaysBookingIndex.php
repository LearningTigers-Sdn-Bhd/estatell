<?php

namespace App\Services\Onboarding\Wastays;

use Carbon\CarbonImmutable;

/**
 * Looks up what a wastays booking needs when the replay creates it: its room row, nights and prices, guests, group
 * name, the dates it had when it was created, and when it was checked in. It reads the bundle once and keeps the
 * indexes. It reads only. See docs/migration-plan/grand-eastern-hotel.md "Day loop".
 */
final class WastaysBookingIndex
{
    /** @var array<string, array<string, string|null>>|null */
    private ?array $bookings = null;

    /** @var array<string, array<string, string|null>>|null */
    private ?array $rooms = null;

    /** @var array<string, list<array<string, string|null>>>|null */
    private ?array $guests = null;

    /** @var array<string, string>|null */
    private ?array $groups = null;

    /** @var array<string, list<array<string, string|null>>>|null */
    private ?array $audit = null;

    /** @var array<string, true>|null */
    private ?array $earlyDepartures = null;

    public function __construct(private readonly WastaysBundle $bundle) {}

    /** @return array<string, string|null> */
    public function booking(string $id): array
    {
        $this->bookings ??= $this->keyed('bookings', 'id');

        return $this->bookings[$id] ?? [];
    }

    /** @return array<string, string|null> */
    public function room(string $bookingId): array
    {
        $this->rooms ??= $this->keyed('booking_rooms', 'booking_id');

        return $this->rooms[$bookingId] ?? [];
    }

    /**
     * The nights of the booking and their net prices, by date, from the nightly rate snapshot.
     *
     * @return array<string, string>
     */
    public function nights(string $bookingId): array
    {
        $nights = [];

        foreach (json_decode((string) ($this->room($bookingId)['nightly_rate_snapshot'] ?? ''), true) ?: [] as $date => $night) {
            $nights[(string) $date] = (string) $night['price'];
        }

        ksort($nights);

        return $nights;
    }

    /**
     * The wastays guest ids of the booking, the primary guest first.
     *
     * @return list<string>
     */
    public function guestIds(string $bookingId): array
    {
        if ($this->guests === null) {
            $this->guests = [];

            foreach ($this->rows('booking_guests') as $row) {
                $this->guests[(string) $row['booking_id']][] = $row;
            }
        }

        $rows = $this->guests[$bookingId] ?? [];
        usort($rows, fn (array $a, array $b): int => [$b['role'] === 'primary', (int) $a['id']] <=> [$a['role'] === 'primary', (int) $b['id']]);

        return array_map(fn (array $row): string => (string) $row['guest_id'], $rows);
    }

    public function groupName(string $bookingId): ?string
    {
        $this->groups ??= array_map(fn (array $row): string => (string) $row['name'], $this->keyed('group_bookings', 'id'));
        $groupId = $this->booking($bookingId)['group_booking_id'] ?? null;

        return $groupId === null ? null : ($this->groups[$groupId] ?? null);
    }

    /**
     * The arrival and departure dates the booking had when it was created: the old dates of its first date change, else
     * the dates it has now. A stay that arrives and leaves on one day gets one night (the replay checks it out the next day).
     *
     * @return array{0: string, 1: string}
     */
    public function firstDates(string $bookingId): array
    {
        $booking = $this->booking($bookingId);
        $arrival = substr((string) $booking['check_in'], 0, 10);
        $departure = substr((string) $booking['check_out'], 0, 10);

        foreach ($this->audit($bookingId) as $row) {
            if ($row['action_type'] !== 'update') {
                continue;
            }

            $old = json_decode((string) $row['old_value'], true) ?: [];
            $new = json_decode((string) $row['new_value'], true) ?: [];

            if (($old['check_in'] ?? null) !== ($new['check_in'] ?? null) || ($old['check_out'] ?? null) !== ($new['check_out'] ?? null)) {
                $arrival = substr((string) ($old['check_in'] ?? $arrival), 0, 10);
                $departure = substr((string) ($old['check_out'] ?? $departure), 0, 10);

                break;
            }
        }

        if ($departure <= $arrival) {
            $departure = CarbonImmutable::parse($arrival)->addDay()->toDateString();
        }

        return [$arrival, $departure];
    }

    public function hasCheckIn(string $bookingId): bool
    {
        return $this->firstCheckInAt($bookingId) !== null;
    }

    public function firstCheckInAt(string $bookingId): ?CarbonImmutable
    {
        foreach ($this->audit($bookingId) as $row) {
            if ($row['action_type'] === 'check_in') {
                return CarbonImmutable::parse((string) $row['occurred_at']);
            }
        }

        return null;
    }

    public function checkOutAt(string $bookingId): ?CarbonImmutable
    {
        $at = null;

        foreach ($this->audit($bookingId) as $row) {
            if ($row['action_type'] === 'check_out') {
                $at = CarbonImmutable::parse((string) $row['occurred_at']);
            }
        }

        return $at;
    }

    public function hasEarlyDeparture(string $bookingId): bool
    {
        if ($this->earlyDepartures === null) {
            $this->earlyDepartures = [];

            foreach ($this->rows('folio_transactions') as $row) {
                if ($row['category'] === 'early_departure_charge' && $row['reversal_of_transaction_id'] === null) {
                    $this->earlyDepartures[(string) $row['source_booking_id']] = true;
                }
            }
        }

        return isset($this->earlyDepartures[$bookingId]);
    }

    /**
     * The audit rows of the booking, oldest first.
     *
     * @return list<array<string, string|null>>
     */
    private function audit(string $bookingId): array
    {
        if ($this->audit === null) {
            $this->audit = [];

            foreach ($this->rows('booking_audit_logs') as $row) {
                if ($row['auditable_type'] === 'Booking') {
                    $this->audit[(string) $row['auditable_id']][] = $row;
                }
            }

            foreach ($this->audit as $id => $rows) {
                usort($rows, fn (array $a, array $b): int => [$a['occurred_at'], (int) $a['id']] <=> [$b['occurred_at'], (int) $b['id']]);
                $this->audit[$id] = $rows;
            }
        }

        return $this->audit[$bookingId] ?? [];
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    private function keyed(string $table, string $key): array
    {
        $rows = [];

        foreach ($this->rows($table) as $row) {
            $rows[(string) $row[$key]] ??= $row;
        }

        return $rows;
    }

    /**
     * @return iterable<array<string, string|null>>
     */
    private function rows(string $table): iterable
    {
        return array_key_exists($table.'.csv', $this->bundle->manifest->files) ? $this->bundle->rows($table) : [];
    }
}
