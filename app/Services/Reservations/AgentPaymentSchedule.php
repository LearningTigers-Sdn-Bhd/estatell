<?php

namespace App\Services\Reservations;

use App\Enums\FolioStatus;
use App\Enums\TransactionKind;
use App\Models\FolioLine;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Services\CityLedger\AgentPaymentTerms;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * The agent payment schedule of a reservation: the deadlines set at booking, and the amounts worked out from the
 * agent's part of the booking (`AgentBill`: what lands on a folio a company pays) and the payments on those folios. The
 * rules follow wastays (`Bookings::PaymentSchedule`): deadlines are wall-clock in the property time zone, the deposit
 * is due within the hold but never after arrival and never less than 30 minutes from booking, the rest is due at the
 * end of the day `days` before arrival, and when that day is not after the deposit day there is one stage for the
 * whole booking. It writes nothing. See docs/15-city-ledger/06-agent-payment-schedule.md.
 */
class AgentPaymentSchedule
{
    public const int MINIMUM_HOLD_MINUTES = 30;

    public function __construct(private AgentBill $agentBill, private AgentPaymentTerms $terms) {}

    /**
     * @param  array{percent: int, hours: int, days: int}  $terms
     * @return array{deposit_percent: int, deposit_due_at: CarbonImmutable, full_payment_due_at: ?CarbonImmutable}
     */
    public function deadlines(array $terms, PropertySetting $settings, CarbonImmutable $bookedAt, CarbonImmutable $arrivalDate): array
    {
        $zone = $settings->time_zone ?: config('app.timezone');
        $arrival = CarbonImmutable::parse($arrivalDate->toDateString().' '.$settings->check_in_time, $zone);
        $deposit = $bookedAt->addHours($terms['hours']);
        $deposit = $deposit->greaterThan($arrival) ? $arrival : $deposit;
        $minimum = $bookedAt->addMinutes(self::MINIMUM_HOLD_MINUTES);
        $deposit = $deposit->lessThan($minimum) ? $minimum : $deposit;
        $full = CarbonImmutable::parse($arrivalDate->subDays($terms['days'])->toDateString(), $zone)->endOfDay();
        $singleStage = $terms['percent'] >= 100 || $full->toDateString() <= $deposit->setTimezone($zone)->toDateString();

        return [
            'deposit_percent' => $singleStage ? 100 : $terms['percent'],
            'deposit_due_at' => $deposit->utc(),
            'full_payment_due_at' => $singleStage ? null : $full->utc(),
        ];
    }

    /**
     * The stages of a reservation with a schedule, each with its amount, due time and whether it is paid or late.
     * Empty when the reservation has none, or its company no longer pays per booking.
     *
     * @return list<array{kind: string, amount: string, due_at: CarbonImmutable, paid: bool, overdue: bool}>
     */
    public function stages(Reservation $reservation, ?CarbonImmutable $now = null): array
    {
        if ($reservation->deposit_due_at === null || $reservation->deposit_percent === null) {
            return [];
        }

        return $this->stagesFrom($reservation, $this->total($reservation), $this->paid($reservation), $now);
    }

    /**
     * The stages for a total and a paid amount the caller already has.
     *
     * @return list<array{kind: string, amount: string, due_at: CarbonImmutable, paid: bool, overdue: bool}>
     */
    public function stagesFrom(Reservation $reservation, BigDecimal $total, BigDecimal $paid, ?CarbonImmutable $now = null): array
    {
        if ($reservation->deposit_due_at === null || $reservation->deposit_percent === null) {
            return [];
        }

        $company = $reservation->loadMissing('company')->company;

        if ($company === null || ! $this->terms->applyTo($company)) {
            return [];
        }

        $now ??= now()->toImmutable();
        $deposit = $total->multipliedBy($reservation->deposit_percent)->dividedBy(100, 2, RoundingMode::Up);
        $stage = fn (string $kind, BigDecimal $amount, BigDecimal $covers, CarbonImmutable $dueAt): array => [
            'kind' => $kind,
            'amount' => (string) $amount->toScale(2),
            'due_at' => $dueAt,
            'paid' => $paid->isGreaterThanOrEqualTo($covers),
            'overdue' => ! $paid->isGreaterThanOrEqualTo($covers) && $now->greaterThan($dueAt),
        ];

        if ($reservation->full_payment_due_at === null) {
            return [$stage('full', $total, $total, $reservation->deposit_due_at->toImmutable())];
        }

        return [
            $stage('deposit', $deposit, $deposit, $reservation->deposit_due_at->toImmutable()),
            $stage('balance', $total->minus($deposit), $total, $reservation->full_payment_due_at->toImmutable()),
        ];
    }

    /**
     * What is still to pay on the first stage that is not paid, or null when all are paid.
     *
     * @return array{kind: string, amount_due: string, due_at: CarbonImmutable, overdue: bool}|null
     */
    public function nextDue(Reservation $reservation, ?CarbonImmutable $now = null): ?array
    {
        $stages = $this->stages($reservation, $now);
        $paid = $this->paid($reservation);
        $covered = BigDecimal::zero();

        foreach ($stages as $stage) {
            $covered = $covered->plus($stage['amount']);

            if (! $stage['paid']) {
                return ['kind' => $stage['kind'], 'amount_due' => (string) BigDecimal::max(BigDecimal::zero(), $covered->minus($paid))->toScale(2), 'due_at' => $stage['due_at'], 'overdue' => $stage['overdue']];
            }
        }

        return null;
    }

    /**
     * The agent's part of the booking: what its folios already hold (charges, taxes and adjustments) and what is still
     * to post on them. What the guest pays at the resort does not count.
     */
    public function total(Reservation $reservation): BigDecimal
    {
        return BigDecimal::of($this->agentBill->for($reservation)->agentTotal);
    }

    /**
     * The money received on the folios a company pays, net of refunds. What the guest paid on their own folio does not
     * count: it pays the guest's part.
     */
    public function paid(Reservation $reservation): BigDecimal
    {
        $payments = (string) FolioLine::query()
            ->whereHas('folio', fn ($folios) => $folios->where('reservation_id', $reservation->id)->whereNotNull('payer_company_id')->where('status', '<>', FolioStatus::Voided->value))
            ->where('kind', TransactionKind::Payment->value)
            ->sum('amount');

        return BigDecimal::of($payments)->negated()->toScale(2);
    }
}
