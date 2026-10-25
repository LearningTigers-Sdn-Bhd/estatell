<?php

namespace App\Services\Onboarding\Wastays;

use App\Enums\WastaysEventType;
use App\Models\ChannelConnection;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads the business dates of a wastays bundle and sorts what happened on each into one timeline. A row belongs to the
 * day whose window in `hotel_business_dates` holds its `created_at` (`opened_at <= created_at < closed_at`); `posting_date`
 * is never used, because room, tax, correction and early-departure lines carry the night date. Rows the audit posts
 * itself (room and tax lines of the night audit, catch-up and blocker resolution) are left out. It reads only.
 * See docs/migration-plan/grand-eastern-hotel.md "Day loop".
 */
final class WastaysDay
{
    private const array AUDIT_LINES = ['night_audit', 'catch_up', 'audit_blocker_resolution'];

    private const array STAY_ACTIONS = ['update', 'cancel', 'void', 'undo_check_out'];

    /** @var array<string, array{opened: CarbonImmutable, closed: CarbonImmutable|null}>|null */
    private ?array $windows = null;

    /** @var array<string, list<WastaysEvent>>|null */
    private ?array $byDay = null;

    /** @var list<WastaysEvent> */
    private array $unassigned = [];

    public function __construct(private readonly WastaysBundle $bundle) {}

    /**
     * The wastays business dates, oldest first.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        return array_keys($this->windows());
    }

    /**
     * @return array{opened: CarbonImmutable, closed: CarbonImmutable|null}|null
     */
    public function window(string $date): ?array
    {
        return $this->windows()[$date] ?? null;
    }

    /**
     * The events of one business date in replay order: by time, then by the tie order of the event type, then by id.
     *
     * @return list<WastaysEvent>
     */
    public function timeline(string $date): array
    {
        return $this->grouped()[$date] ?? [];
    }

    /**
     * Events whose time falls in no business date window. The replay stops on them.
     *
     * @return list<WastaysEvent>
     */
    public function unassigned(): array
    {
        $this->grouped();

        return $this->unassigned;
    }

    /**
     * The night audit row of a business date, or null.
     *
     * @return array<string, string|null>|null
     */
    public function nightAudit(string $date): ?array
    {
        return $this->rows('night_audits')->first(fn (array $row): bool => $row['business_date'] === $date);
    }

    /**
     * Why this day cannot be replayed into the property now. Empty when it can.
     *
     * @return list<string>
     */
    public function refusals(Property $property, string $date): array
    {
        $refusals = [];

        if ($this->window($date) === null) {
            $refusals[] = "{$date} is not a wastays business date.";
        }

        if (ChannelConnection::query()->whereHas('channelProperty', fn ($query) => $query->where('property_id', $property->id))->exists()) {
            $refusals[] = 'A channel is connected. Disconnect it before the replay.';
        }

        $current = app(CurrentBusinessDate::class)->date($property)?->toDateString();

        if ($current !== $date) {
            $refusals[] = 'The estatell business date is '.($current ?? 'not set').', not '.$date.'.';
        }

        foreach (['payment_transactions', 'refund_requests'] as $table) {
            if ($this->rows($table)->isNotEmpty()) {
                $refusals[] = "The bundle has {$table}. The replay does not read them.";
            }
        }

        return $refusals;
    }

    /**
     * @return array<string, array{opened: CarbonImmutable, closed: CarbonImmutable|null}>
     */
    private function windows(): array
    {
        if ($this->windows !== null) {
            return $this->windows;
        }

        $windows = [];

        foreach ($this->rows('hotel_business_dates')->sortBy('business_date') as $row) {
            if ($row['opened_at'] === null) {
                continue;
            }

            $windows[(string) $row['business_date']] = [
                'opened' => CarbonImmutable::parse($row['opened_at']),
                'closed' => $row['closed_at'] === null ? null : CarbonImmutable::parse($row['closed_at']),
            ];
        }

        return $this->windows = $windows;
    }

    /**
     * @return array<string, list<WastaysEvent>>
     */
    private function grouped(): array
    {
        if ($this->byDay !== null) {
            return $this->byDay;
        }

        $byDay = array_fill_keys($this->dates(), []);

        foreach ($this->events() as $event) {
            $date = $this->dateOf($event->at);

            if ($date === null) {
                $this->unassigned[] = $event;

                continue;
            }

            $byDay[$date][] = $event;
        }

        foreach ($byDay as $date => $events) {
            usort($events, fn (WastaysEvent $a, WastaysEvent $b): int => [$a->at->getTimestamp(), $a->at->micro, $a->type->rank(), (int) $a->sourceId, $a->sourceTable]
                <=> [$b->at->getTimestamp(), $b->at->micro, $b->type->rank(), (int) $b->sourceId, $b->sourceTable]);
            $byDay[$date] = $events;
        }

        return $this->byDay = $byDay;
    }

    private function dateOf(CarbonImmutable $at): ?string
    {
        foreach ($this->windows() as $date => $window) {
            if ($at->gte($window['opened']) && ($window['closed'] === null || $at->lt($window['closed']))) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @return iterable<WastaysEvent>
     */
    private function events(): iterable
    {
        foreach ($this->rows('bookings') as $row) {
            yield $this->event(WastaysEventType::Create, $row['created_at'], 'bookings', $row['id'], $row['id'], $row);
        }

        foreach ($this->rows('booking_audit_logs') as $row) {
            $type = $this->auditType($row);

            if ($type !== null) {
                yield $this->event($type, $row['occurred_at'], 'booking_audit_logs', $row['id'], $row['auditable_id'], $row);
            }
        }

        foreach ($this->rows('folio_transactions') as $row) {
            $type = $this->folioType($row);

            if ($type !== null) {
                yield $this->event($type, $row['created_at'], 'folio_transactions', $row['id'], $row['source_booking_id'], $row);
            }
        }

        foreach ($this->rows('deposits') as $row) {
            // A prepayment is money a booking paid before it was made. The part wastays applied to a folio is already a
            // payment line, and the rest is held outside any folio, so only a security deposit is replayed.
            if (($row['kind'] ?? null) === 'prepayment') {
                continue;
            }

            yield $this->event(WastaysEventType::Deposit, $row['received_at'], 'deposits', $row['id'], $row['booking_id'], $row);
        }
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private function event(WastaysEventType $type, ?string $at, string $table, ?string $id, ?string $bookingId, array $row): WastaysEvent
    {
        return new WastaysEvent($type, CarbonImmutable::parse((string) $at), $table, (string) $id, $bookingId, $row);
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private function auditType(array $row): ?WastaysEventType
    {
        $action = (string) $row['action_type'];

        if ($row['auditable_type'] !== 'Booking') {
            return null;
        }

        if (in_array($action, ['check_in', 'edit_check_in', 'undo_check_in'], true)) {
            return WastaysEventType::CheckIn;
        }

        if ($action === 'check_out') {
            return WastaysEventType::CheckOut;
        }

        if (! in_array($action, self::STAY_ACTIONS, true)) {
            return null;
        }

        return $action !== 'update' || $this->stayChanged($row) ? WastaysEventType::StayChange : null;
    }

    /**
     * Whether an update row changed the dates or the room type.
     *
     * @param  array<string, string|null>  $row
     */
    private function stayChanged(array $row): bool
    {
        $old = json_decode((string) $row['old_value'], true) ?: [];
        $new = json_decode((string) $row['new_value'], true) ?: [];

        return array_any(['check_in', 'check_out', 'room_category'], fn (string $key): bool => ($old[$key] ?? null) !== ($new[$key] ?? null));
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private function folioType(array $row): ?WastaysEventType
    {
        $category = (string) $row['category'];
        $source = json_decode((string) $row['metadata'], true)['posting_source'] ?? null;

        if ($category === 'tax') {
            return null;
        }

        if ($category === 'accommodation') {
            return match (true) {
                in_array($source, self::AUDIT_LINES, true) => null,
                $source === 'historical_nightly_charge_repair' && $row['moved_from_transaction_id'] !== null => WastaysEventType::Repair,
                $source === 'historical_nightly_charge_repair' => null,
                default => WastaysEventType::Charge,
            };
        }

        return match (true) {
            $category === 'early_departure_charge' && $row['reversal_of_transaction_id'] === null => WastaysEventType::EarlyDeparture,
            $row['reversal_of_transaction_id'] !== null => WastaysEventType::Reversal,
            $category === 'refund' => WastaysEventType::Refund,
            $row['transaction_type'] === 'payment' => WastaysEventType::Payment,
            in_array($category, ['adjustment', 'correction'], true) => WastaysEventType::Adjustment,
            default => WastaysEventType::Charge,
        };
    }

    /**
     * @return Collection<int, array<string, string|null>>
     */
    private function rows(string $table): Collection
    {
        if (! array_key_exists($table.'.csv', $this->bundle->manifest->files)) {
            return collect();
        }

        return $this->bundle->rows($table)->collect();
    }
}
