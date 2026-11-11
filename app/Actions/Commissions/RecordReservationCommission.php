<?php

namespace App\Actions\Commissions;

use App\Data\Commissions\CommissionEntryInputData;
use App\Enums\CommissionEntryType;
use App\Enums\CommissionService;
use App\Models\CommissionEntry;
use App\Models\Reservation;
use App\Services\Commissions\CommissionRates;
use App\Services\Commissions\CommissionRule;
use App\Services\Commissions\PropertyCommissionPeriods;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Works out each service's commission for an ended reservation and keeps the ledger in line with it. When the amount
 * changed since the last run (a fee reversed on the folio, a refund), the live entry is reversed and a new one posted,
 * both on the given due date. The same amount writes nothing. The rate is the one in force on the day the reservation
 * was made. A service is charged only when the booking's check-out date falls in one of the property's periods for it.
 * See docs/30-commission-fees/02-commission-rules.md#corrections.
 */
class RecordReservationCommission
{
    public function __construct(
        private CommissionRates $commissionRates,
        private PostCommissionEntry $postCommissionEntry,
        private ReverseCommissionEntry $reverseCommissionEntry,
        private PropertyCommissionPeriods $commissionPeriods,
    ) {}

    public function __invoke(Reservation $reservation, CarbonImmutable $dueDate): void
    {
        $chargeDate = $this->commissionPeriods->chargeDate($reservation);

        foreach (CommissionService::cases() as $service) {
            /** @var CommissionRule $rule */
            $rule = app($service->rule());

            if ($rule->appliesTo($reservation) && $this->commissionPeriods->covers($reservation->property_id, $service, $chargeDate)) {
                $this->record($reservation, $service, $rule, $dueDate);
            }
        }
    }

    private function record(Reservation $reservation, CommissionService $service, CommissionRule $rule, CarbonImmutable $dueDate): void
    {
        $rate = $this->commissionRates->rateOn($service, $reservation->created_at->toImmutable());

        if ($rate === null) {
            return;
        }

        $base = BigDecimal::of($rule->base($reservation));
        $wanted = $base->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HalfUp)->negated();

        $entries = CommissionEntry::query()
            ->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)
            ->where('type', CommissionEntryType::Commission)
            ->where('service', $service)
            ->get();

        $current = $entries->reduce(fn (BigDecimal $sum, CommissionEntry $entry): BigDecimal => $sum->plus($entry->amount), BigDecimal::zero());

        if ($current->isEqualTo($wanted)) {
            return;
        }

        $reversed = $entries->pluck('reverses_entry_id')->filter()->all();

        foreach ($entries as $entry) {
            if ($entry->reverses_entry_id === null && ! in_array($entry->id, $reversed, true)) {
                ($this->reverseCommissionEntry)($entry, $dueDate);
            }
        }

        if ($wanted->isZero()) {
            return;
        }

        $attempt = $entries->whereNull('reverses_entry_id')->count() + 1;

        ($this->postCommissionEntry)($reservation->property, new CommissionEntryInputData(
            type: CommissionEntryType::Commission,
            amount: (string) $wanted,
            dueDate: $dueDate,
            reservationId: $reservation->id,
            service: $service,
            baseAmount: (string) $base->toScale(2),
            rate: $rate,
            idempotencyKey: "commission:{$service->value}:{$reservation->id}:{$attempt}",
        ));
    }
}
