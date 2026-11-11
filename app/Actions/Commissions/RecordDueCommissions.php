<?php

namespace App\Actions\Commissions;

use App\Enums\BookingSourceType;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The hourly run of Commission fees. It looks at every booking engine reservation whose stay ended in the last 90 days or
 * has not ended, and records the ones whose units have all ended: the money, then the commission, on the property's
 * current business date. Each write is idempotent, so a run that finds nothing new writes nothing. Returns how many
 * reservations it recorded. See docs/30-commission-fees/02-commission-rules.md#due-date.
 */
class RecordDueCommissions
{
    public const int LOOKBACK_DAYS = 90;

    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private RecordBookingEngineMoney $recordBookingEngineMoney,
        private RecordReservationCommission $recordReservationCommission,
    ) {}

    public function __invoke(): int
    {
        $recorded = 0;

        Reservation::query()
            ->whereHas('bookingSource', fn (Builder $query) => $query->where('type', BookingSourceType::BookingEngine))
            ->whereHas('units', fn (Builder $query) => $query->where('departure_date', '>=', today()->subDays(self::LOOKBACK_DAYS)->toDateString()))
            ->orderBy('id')
            ->chunkById(100, function ($reservations) use (&$recorded): void {
                foreach ($reservations as $reservation) {
                    $recorded += (int) $this->record($reservation);
                }
            });

        return $recorded;
    }

    private function record(Reservation $reservation): bool
    {
        return DB::transaction(function () use ($reservation): bool {
            $locked = Reservation::query()->with(['property', 'bookingSource', 'units'])->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->units->isEmpty() || $locked->units->contains(fn (ReservationUnit $unit): bool => ! $unit->status->hasEnded())) {
                return false;
            }

            $dueDate = $this->currentBusinessDate->date($locked->property);

            if ($dueDate === null) {
                return false;
            }

            ($this->recordBookingEngineMoney)($locked, $dueDate);
            ($this->recordReservationCommission)($locked, $dueDate);

            return true;
        });
    }
}
