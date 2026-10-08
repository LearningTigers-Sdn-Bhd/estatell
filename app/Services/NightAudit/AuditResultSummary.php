<?php

namespace App\Services\NightAudit;

use App\Enums\PaymentStatus;
use App\Models\ArPayment;
use App\Models\DailyRevenue;
use App\Models\DailyStatistic;
use App\Models\NightAudit;
use App\Models\Payment;
use App\Services\Meals\MealCounts;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Gathers the content of the night audit result messages and of the night audit packet PDF from the snapshot tables, the
 * payments of the date (folio payments and city ledger payments), the meals of the date that opens and the exceptions.
 * It reads only and writes nothing.
 * See docs/20-night-audit/05-statistics.md.
 */
class AuditResultSummary
{
    public function __construct(private MealCounts $mealCounts) {}

    /**
     * @param  bool  $allExceptions  True for the packet: every exception of the audit, open and resolved. The message
     *                               keeps the default and lists only the open ones.
     */
    public function forAudit(NightAudit $audit, bool $allExceptions = false): AuditSummary
    {
        $audit->loadMissing('property.settings', 'businessDate');
        $property = $audit->property;
        $date = CarbonImmutable::parse($audit->businessDate->date->toDateString());
        $currency = $property->settings->currency->value;

        $revenue = DailyRevenue::query()
            ->with('transactionCode')
            ->where('property_id', $property->id)
            ->whereDate('business_date', $date->toDateString())
            ->get()
            ->sortBy(fn (DailyRevenue $row): string => $row->transactionCode->name)
            ->map(fn (DailyRevenue $row): array => [
                'code' => $row->transactionCode->code,
                'name' => $row->transactionCode->name,
                'amount' => Money::of($row->amount, $currency),
                'lines' => $row->line_count,
            ])
            ->values()
            ->all();

        $ofDate = $this->paymentsOfDate($audit, $date);
        $payments = $ofDate
            ->whereNull('refund_of_id')
            ->map(fn (Payment $payment): array => ['name' => $payment->paymentMethod->name, 'amount' => (string) $payment->amount])
            ->concat($this->arPaymentsOfDate($audit, $date)->map(fn (ArPayment $payment): array => ['name' => $payment->paymentMethod->name, 'amount' => (string) $payment->amount]))
            ->groupBy('name')
            ->map(fn (Collection $group, string $name): array => [
                'name' => $name,
                'count' => $group->count(),
                'amount' => $group->reduce(fn (Money $sum, array $payment): Money => $sum->plus($payment['amount']), Money::zero($currency)),
            ])
            ->sortKeys()
            ->values()
            ->all();

        $refunds = Money::of((string) $ofDate->whereNotNull('refund_of_id')->sum('amount'), $currency);
        $mealDate = $date->addDay();

        return new AuditSummary(
            $date,
            $mealDate,
            $currency,
            DailyStatistic::query()->where('property_id', $property->id)->whereDate('business_date', $date->toDateString())->first(),
            $revenue,
            $payments,
            $refunds,
            $this->mealCounts->forDate($property, $mealDate),
            $audit->exceptions()->when(! $allExceptions, fn ($exceptions) => $exceptions->open())->with('reservationUnit.reservation')->orderBy('id')->get(),
        );
    }

    /**
     * The completed payments and refunds whose folio line was posted on the business date.
     *
     * @return Collection<int, Payment>
     */
    private function paymentsOfDate(NightAudit $audit, CarbonImmutable $date): Collection
    {
        return Payment::query()
            ->with('paymentMethod')
            ->where('payments.property_id', $audit->property_id)
            ->where('payments.status', PaymentStatus::Completed->value)
            ->whereHas('folioLine', fn ($lines) => $lines->whereDate('posting_date', $date->toDateString()))
            ->get();
    }

    /**
     * The city ledger payments received on the business date that are not voided, the same rule as the Payments report.
     *
     * @return Collection<int, ArPayment>
     */
    private function arPaymentsOfDate(NightAudit $audit, CarbonImmutable $date): Collection
    {
        return ArPayment::query()
            ->with('paymentMethod')
            ->where('property_id', $audit->property_id)
            ->whereDate('received_on', $date->toDateString())
            ->whereNull('voided_at')
            ->get();
    }
}
