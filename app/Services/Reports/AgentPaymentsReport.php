<?php

namespace App\Services\Reports;

use App\Data\Reports\AgentPaymentDueRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Reservations\AgentPaymentSchedule;
use Carbon\CarbonImmutable;

/**
 * The agent bookings that still owe a stage of their payment schedule, overdue first, for the desk to follow up or
 * release. Each reservation is priced with `AgentPaymentSchedule`, one at a time, so the list suits a property's open
 * agent bookings rather than years of history. See docs/15-city-ledger/06-agent-payment-schedule.md#follow-up.
 */
class AgentPaymentsReport implements HasTotals, Report
{
    public function __construct(private AgentPaymentSchedule $schedule) {}

    /**
     * @param  list<int|string>|null  $rowKeys  Reservation ids.
     * @return list<AgentPaymentDueRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $zone = $property->loadMissing('settings')->settings?->time_zone ?: config('app.timezone');
        $now = now()->toImmutable();
        $rows = [];

        $reservations = Reservation::query()
            ->where('property_id', $property->id)
            ->whereNotNull('deposit_due_at')
            ->agentPaymentsOpen()
            ->when($rowKeys !== null, fn ($query) => $query->whereKey($rowKeys))
            ->with(['company', 'booker', 'property.settings'])
            ->withMin(['units as first_arrival' => fn ($units) => $units->whereIn('status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))], 'arrival_date')
            ->orderBy('deposit_due_at')
            ->get();

        foreach ($reservations as $reservation) {
            $next = $this->schedule->nextDue($reservation, $now);
            $firstArrival = $reservation->getAttribute('first_arrival');

            if ($next === null || ! is_string($firstArrival)) {
                continue;
            }

            $rows[] = new AgentPaymentDueRow(
                key: $reservation->id,
                reservation_id: $reservation->id,
                reservation: $reservation->reference,
                company: $reservation->company->name ?? '',
                arrival: CarbonImmutable::parse($firstArrival)->toDateString(),
                stage: match ($next['kind']) {
                    'deposit' => __('Deposit :percent%', ['percent' => $reservation->deposit_percent]),
                    'balance' => __('Balance'),
                    default => __('Full payment'),
                },
                amount_due: $next['amount_due'],
                due_at: $next['due_at']->toIso8601String(),
                status: $next['overdue'] ? __('Overdue') : __('Due'),
                time_zone: $zone,
                overdue: $next['overdue'],
                stay_ended: $reservation->status === ReservationStatus::Completed,
            );
        }

        usort($rows, fn (AgentPaymentDueRow $first, AgentPaymentDueRow $second): int => [! $first->overdue, $first->due_at] <=> [! $second->overdue, $second->due_at]);

        return $rows;
    }

    /**
     * @param  list<AgentPaymentDueRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'reservation' => null,
            'company' => null,
            'arrival' => null,
            'stage' => null,
            'amount_due' => number_format(array_sum(array_map(fn (AgentPaymentDueRow $row): float => (float) $row->amount_due, $rows)), 2, '.', ''),
            'due_at' => null,
            'status' => null,
        ]);
    }
}
