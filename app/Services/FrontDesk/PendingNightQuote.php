<?php

namespace App\Services\FrontDesk;

use App\Data\Reservations\UnitPendingLineData;
use App\Enums\UnitStatus;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Services\Folio\ScheduledFolioRows;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * The pending night of a unit: the night of the business date while the night audit is pending, and the add-ons dated
 * that day. `applies()` is the one rule `PostPendingNight` and the check-out preview both ask. `lines()` reads what
 * the check-out will post, through `ScheduledFolioRows` (the same quote `PostRoomCharges` uses), and keeps the lines
 * that land on the unit's own folios, so the preview balance matches the folio after the posting. It reads only.
 * See docs/18-front-desk/01-check-in-and-check-out.md#night-audit-pending.
 */
class PendingNightQuote
{
    public function __construct(
        private StayDay $stayDay,
        private ScheduledFolioRows $scheduledFolioRows,
        private CheckOutSettlement $checkOutSettlement,
    ) {}

    /**
     * The audit is pending, the unit is CheckedIn and it has a night on the business date.
     */
    public function applies(ReservationUnit $unit, CarbonImmutable $businessDate): bool
    {
        return $unit->status === UnitStatus::CheckedIn
            && $this->stayDay->isAuditPending($unit->property, $businessDate)
            && $unit->nights()->whereDate('date', $businessDate->toDateString())->exists();
    }

    /**
     * @return list<UnitPendingLineData>
     */
    public function lines(ReservationUnit $unit, CarbonImmutable $businessDate): array
    {
        if (! $this->applies($unit, $businessDate)) {
            return [];
        }

        $date = $businessDate->toDateString();
        $property = $unit->property()->with('settings')->firstOrFail();
        $reservation = Reservation::query()->with('booker')->findOrFail($unit->reservation_id);
        $own = $this->checkOutSettlement->folios($unit)->modelKeys();
        $addOnIds = UnitAddOn::query()->where('reservation_unit_id', $unit->id)->whereNull('folio_line_id')->whereDate('service_date', $date)->pluck('id')->map(fn ($id): string => 'addon:'.$id)->all();
        $lines = [];

        foreach ($this->scheduledFolioRows->forReservation($property, $reservation, []) as $block) {
            if ($block['unit'] !== $unit->id || $block['date'] !== $date || ($block['target'] !== null && ! in_array($block['target'], $own, true))) {
                continue;
            }

            foreach ($block['rows'] as $row) {
                $root = implode(':', array_slice(explode(':', $row->key), 0, 2));

                if (str_starts_with($root, 'addon:') && ! in_array($root, $addOnIds, true)) {
                    continue;
                }

                $lines[] = new UnitPendingLineData($row->description, $row->amount, $row->level);
            }
        }

        return $lines;
    }

    /**
     * @param  list<UnitPendingLineData>  $lines
     */
    public function total(array $lines): BigDecimal
    {
        return array_reduce($lines, fn (BigDecimal $sum, UnitPendingLineData $line): BigDecimal => $sum->plus($line->amount), BigDecimal::zero());
    }
}
