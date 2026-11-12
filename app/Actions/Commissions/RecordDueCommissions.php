<?php

namespace App\Actions\Commissions;

use App\Enums\CommissionService;
use App\Enums\UnitStatus;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Commissions\CommissionRule;
use App\Services\Folio\ReservationFolioActivity;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The hourly run of Commission fees. Each service's rule names its candidate reservations; this keeps the ended ones that
 * are new or changed, and records each on the property's current business date: the booking engine money, then every
 * service's commission. New: nothing recorded yet and a departure in the last 90 days. Changed: already recorded, with a
 * folio line posted or a unit changed in the last 3 hours. Each write is idempotent. Returns how many reservations it
 * recorded. See docs/30-commission-fees/02-commission-rules.md#due-date.
 */
class RecordDueCommissions
{
    public const int LOOKBACK_DAYS = 90;

    public const int CHANGED_WITHIN_HOURS = 3;

    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private ReservationFolioActivity $folioActivity,
        private RecordBookingEngineMoney $recordBookingEngineMoney,
        private RecordReservationCommission $recordReservationCommission,
    ) {}

    public function __invoke(): int
    {
        $ids = collect(CommissionService::cases())
            ->map(fn (CommissionService $service): string => $service->rule())
            ->flatMap(function (string $ruleClass): array {
                /** @var CommissionRule $rule */
                $rule = app($ruleClass);

                return $this->dueAmong($rule->candidates())->pluck('reservations.id')->all();
            })
            ->unique()
            ->sort()
            ->values();

        return $ids->sum(fn (int $id): int => (int) $this->record($id));
    }

    /**
     * @param  Builder<Reservation>  $candidates
     * @return Builder<Reservation>
     */
    private function dueAmong(Builder $candidates): Builder
    {
        $endedStatuses = array_map(fn (UnitStatus $status): string => $status->value, array_filter(UnitStatus::cases(), fn (UnitStatus $status): bool => $status->hasEnded()));
        $since = now()->subHours(self::CHANGED_WITHIN_HOURS);

        return $candidates
            ->whereHas('units')
            ->whereDoesntHave('units', fn (Builder $query) => $query->whereNotIn('status', $endedStatuses))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $new) => $new
                    ->whereNotExists(fn (QueryBuilder $entries) => $entries->from('commission_entries')->whereColumn('commission_entries.reservation_id', 'reservations.id'))
                    ->whereHas('units', fn (Builder $units) => $units->where('departure_date', '>=', today()->subDays(self::LOOKBACK_DAYS)->toDateString())))
                ->orWhere(fn (Builder $changed) => $changed
                    ->whereExists(fn (QueryBuilder $entries) => $entries->from('commission_entries')->whereColumn('commission_entries.reservation_id', 'reservations.id'))
                    ->where(fn (Builder $recent) => $this->folioActivity
                        ->postedSince($recent, $since)
                        ->orWhereHas('units', fn (Builder $units) => $units->where('updated_at', '>=', $since)))));
    }

    private function record(int $reservationId): bool
    {
        return DB::transaction(function () use ($reservationId): bool {
            $locked = Reservation::query()->with(['property', 'bookingSource', 'units'])->lockForUpdate()->findOrFail($reservationId);

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
