<?php

namespace App\Actions\FrontDesk;

use App\Actions\NightAudit\PostRoomCharges;
use App\Actions\NightAudit\PostScheduledAddOns;
use App\Enums\UnitStatus;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Models\User;
use App\Services\FrontDesk\PendingNightQuote;
use App\Services\NightAudit\CurrentBusinessDate;

class PostPendingNight
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PendingNightQuote $pendingNightQuote,
        private PostRoomCharges $postRoomCharges,
        private PostScheduledAddOns $postScheduledAddOns,
    ) {}

    /**
     * While the night audit is pending, post the night of the business date that a CheckedIn unit slept, and every
     * unposted add-on of the unit dated that day, before the unit checks out. The keys are the audit's
     * (`night:{unit_night_id}`, `addon:{id}`), so the audit finds the lines and posts nothing, and a second call writes
     * nothing. It does nothing unless `PendingNightQuote::applies()` says so: a unit that arrived on the calendar date
     * or departs on the business date has no such night and is skipped, not refused. It opens no transaction of its
     * own: the caller's transaction rolls the postings back when a later step refuses. It must run before the status
     * changes, because `PostRoomCharges` refuses a CheckedOut unit for the night of the business date. Returns the lines
     * it wrote now, not the ones it replayed. `$sameDayLeaver` (only the wastays replay passes it, through `CheckOutUnit`)
     * posts the night of the business date while the audit is not pending, for a one-night stay that leaves on its
     * arrival day.
     * See docs/18-front-desk/01-check-in-and-check-out.md#night-audit-pending.
     *
     * @return list<FolioLine>
     *
     * @throws UnitStayRefused when the night or an add-on is refused
     */
    public function __invoke(?User $actor, ReservationUnit $unit, bool $sameDayLeaver = false): array
    {
        $businessDate = $this->currentBusinessDate->date($unit->property);
        $applies = $businessDate !== null && ($sameDayLeaver
            ? $unit->status === UnitStatus::CheckedIn && $unit->nights()->whereDate('date', $businessDate->toDateString())->exists()
            : $this->pendingNightQuote->applies($unit, $businessDate));

        if (! $applies) {
            return [];
        }

        $written = [];
        $night = ($this->postRoomCharges)($actor, $unit, $businessDate);

        if ($night->isRefused()) {
            throw new UnitStayRefused(UnitStayResult::refused((string) $night->refusal));
        }

        if (! $night->replayed) {
            array_push($written, ...$night->lines);
        }

        $addOnIds = UnitAddOn::query()
            ->where('reservation_unit_id', $unit->id)
            ->whereNull('folio_line_id')
            ->whereDate('service_date', $businessDate->toDateString())
            ->orderBy('id')
            ->pluck('id');

        foreach ($addOnIds as $addOnId) {
            $posted = $this->postScheduledAddOns->post($actor, $unit->property, (int) $addOnId, $businessDate);

            if ($posted->isRefused()) {
                throw new UnitStayRefused(UnitStayResult::refused((string) $posted->refusal));
            }

            if (! $posted->replayed) {
                array_push($written, ...$posted->lines);
            }
        }

        return $written;
    }
}
