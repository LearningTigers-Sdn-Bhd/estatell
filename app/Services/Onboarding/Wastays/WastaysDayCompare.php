<?php

namespace App\Services\Onboarding\Wastays;

use App\Enums\PaymentPurpose;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\FolioLine;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Sets the figures of one replayed day in estatell beside the figures of its wastays night audit
 * (`night_audit_financial_summaries`): room revenue, tax, payments, refunds, adjustments and no-show charges. The
 * estatell figures are the folio lines posted on the business date. It reads only.
 * See docs/migration-plan/grand-eastern-hotel.md "Dry run".
 */
final class WastaysDayCompare
{
    /** @var list<string> */
    public const array FIGURES = ['room_revenue', 'tax_revenue', 'payments_total', 'refunds_total', 'adjustments_total', 'no_show_charges'];

    /**
     * @return array<string, string>|null the wastays figures of the date, or null when the bundle has none
     */
    public function wastays(WastaysBundle $bundle, WastaysDay $day, string $date): ?array
    {
        $audit = $day->nightAudit($date);

        if ($audit === null || ! array_key_exists('night_audit_financial_summaries.csv', $bundle->manifest->files)) {
            return null;
        }

        $summary = $bundle->rows('night_audit_financial_summaries')->first(fn (array $row): bool => $row['night_audit_id'] === $audit['id']);

        if ($summary === null) {
            return null;
        }

        $original = $this->original($summary);
        $late = $this->leftOut($bundle, $day, $date);
        $moved = $this->movements($bundle, $day, $date);
        $amended = $this->amendments($bundle, $day, $date);
        $redated = $this->redated($bundle, $day, $date);
        $figures = [];

        foreach (self::FIGURES as $figure) {
            $total = BigDecimal::of($original[$figure])->plus($amended[$figure])->plus($redated[$figure] ?? '0');

            if (isset($late[$figure])) {
                $total = $total->plus($late[$figure]);
            }

            if (isset($moved[$figure])) {
                $total = $total->minus($moved[$figure]);
            }

            $figures[$figure] = $this->decimal((string) $total);
        }

        return $figures;
    }

    /**
     * The figures as the wastays night audit first wrote them: the previous values of the earliest changelog entry, or
     * the summary itself when it was never changed.
     *
     * @param  array<string, string|null>  $summary
     * @return array<string, string>
     */
    private function original(array $summary): array
    {
        $first = collect($this->changelog($summary))->sortBy(fn (array $entry): CarbonImmutable => $entry['at'])->first();
        $values = $first['previous'] ?? $summary;

        return array_combine(self::FIGURES, array_map(fn (string $figure): string => $this->decimal((string) ($values[$figure] ?? $summary[$figure])), self::FIGURES));
    }

    /**
     * What wastays changed in any summary during the date's window, new values less previous ones. wastays changed a
     * closed day's summary when it repaired that day later; estatell replays the repair on the day it was made.
     *
     * @return array<string, BigDecimal>
     */
    private function amendments(WastaysBundle $bundle, WastaysDay $day, string $date): array
    {
        $window = $day->window($date);
        $totals = array_fill_keys(self::FIGURES, BigDecimal::zero());

        if ($window === null) {
            return $totals;
        }

        $nights = $bundle->rows('night_audits')->mapWithKeys(fn (array $row): array => [(string) $row['id'] => (string) $row['business_date']])->all();
        $index = new WastaysBookingIndex($bundle);

        foreach ($bundle->rows('night_audit_financial_summaries') as $summary) {
            if ($this->changelog($summary) === []) {
                continue;
            }

            $since = CarbonImmutable::parse((string) $summary['created_at']);

            foreach (collect($this->changelog($summary))->sortBy(fn (array $entry): CarbonImmutable => $entry['at']) as $entry) {
                $counted = $this->countedOnTheirDay($bundle, $day, $index, $nights[(string) $summary['night_audit_id']] ?? '', $since, $entry['at']);
                $since = $entry['at'];

                if ($entry['at']->lt($window['opened']) || ($window['closed'] !== null && $entry['at']->gte($window['closed']))) {
                    continue;
                }

                foreach (self::FIGURES as $figure) {
                    $totals[$figure] = $totals[$figure]->plus((string) ($entry['new'][$figure] ?? '0'))->minus((string) ($entry['previous'][$figure] ?? '0'))->minus($counted[$figure] ?? '0');
                }
            }
        }

        return $totals;
    }

    /**
     * Left-out lines of the night `$night` posted between two summary changes. A change recounted them into that
     * night's summary, but they already count on the day they were posted, so the change is taken without them.
     *
     * @return array<string, BigDecimal>
     */
    private function countedOnTheirDay(WastaysBundle $bundle, WastaysDay $day, WastaysBookingIndex $index, string $night, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = [];

        foreach ($bundle->rows('folio_transactions') as $row) {
            if (($row['posting_date'] ?? null) !== $night) {
                continue;
            }

            $at = CarbonImmutable::parse((string) $row['created_at']);
            $postedOn = $this->dateOf($day, $at);
            $figure = $postedOn === null || $at->lt($from) || $at->gte($to) ? null : ($this->leftOutFigure($row, $postedOn, $index) ?? $this->redatedFigure($bundle, $day, $index, $row));

            if ($figure !== null) {
                $totals[$figure] = ($totals[$figure] ?? BigDecimal::zero())->plus((string) $row['amount']);
            }
        }

        return $totals;
    }

    /**
     * Closed-night repairs of a stay that estatell creates with its corrected dates (see `WastaysBookingIndex::firstDates()`).
     * estatell posts the night on the later of the night and the day the booking was created, so the repair counts there,
     * not on the day wastays posted it.
     *
     * @return array<string, BigDecimal>
     */
    private function redated(WastaysBundle $bundle, WastaysDay $day, string $date): array
    {
        $index = new WastaysBookingIndex($bundle);
        $totals = [];

        foreach ($bundle->rows('folio_transactions') as $row) {
            $figure = $this->redatedFigure($bundle, $day, $index, $row);

            if ($figure === null) {
                continue;
            }

            $created = $this->dateOf($day, CarbonImmutable::parse((string) $index->booking((string) $row['source_booking_id'])['created_at']));

            if (max((string) $row['posting_date'], (string) $created) === $date) {
                $totals[$figure] = ($totals[$figure] ?? BigDecimal::zero())->plus((string) $row['amount']);
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private function redatedFigure(WastaysBundle $bundle, WastaysDay $day, WastaysBookingIndex $index, array $row): ?string
    {
        $source = json_decode((string) ($row['metadata'] ?? ''), true)['posting_source'] ?? null;

        if ($source !== 'historical_nightly_charge_repair' || ($row['reversal_of_transaction_id'] ?? null) !== null || ($row['moved_from_transaction_id'] ?? null) !== null) {
            return null;
        }

        $figure = match ($row['category']) {
            'accommodation' => 'room_revenue',
            'tax' => 'tax_revenue',
            default => null,
        };
        $booking = $index->booking((string) ($row['source_booking_id'] ?? ''));

        if ($figure === null || $booking === []) {
            return null;
        }

        $created = $this->dateOf($day, CarbonImmutable::parse((string) $booking['created_at']));
        [$arrival, $departure] = $index->firstDates((string) $row['source_booking_id'], $created, $day);

        return $arrival <= (string) $row['posting_date'] && (string) $row['posting_date'] < $departure ? $figure : null;
    }

    private function dateOf(WastaysDay $day, CarbonImmutable $at): ?string
    {
        return collect($day->dates())->first(function (string $date) use ($day, $at): bool {
            $window = $day->window($date);

            return $window !== null && $at->gte($window['opened']) && ($window['closed'] === null || $at->lt($window['closed']));
        });
    }

    /**
     * @param  array<string, string|null>  $summary
     * @return list<array{at: CarbonImmutable, previous: array<string, mixed>, new: array<string, mixed>}>
     */
    private function changelog(array $summary): array
    {
        $entries = json_decode((string) ($summary['changelog'] ?? ''), true) ?: [];

        return array_values(array_map(fn (array $entry): array => [
            'at' => CarbonImmutable::parse((string) $entry['timestamp'])->utc(),
            'previous' => $entry['previous_values'] ?? [],
            'new' => $entry['new_values'] ?? [],
        ], $entries));
    }

    /**
     * Lines wastays posted during the date's window that its summary leaves out but estatell counts on that day:
     * catch-up nights (an earlier night's date and no night audit), the fee of a same-day leaver (estatell keeps it as
     * a night of room revenue), the tax of an early departure fee dated to an earlier night and a staff adjustment and a payment dated
     * to an earlier night.
     *
     * @return array<string, BigDecimal> keyed room_revenue, tax_revenue, adjustments_total and payments_total
     */
    private function leftOut(WastaysBundle $bundle, WastaysDay $day, string $date): array
    {
        $window = $day->window($date);
        $totals = ['room_revenue' => BigDecimal::zero(), 'tax_revenue' => BigDecimal::zero(), 'adjustments_total' => BigDecimal::zero(), 'payments_total' => BigDecimal::zero()];

        if ($window === null) {
            return $totals;
        }

        $index = new WastaysBookingIndex($bundle);

        foreach ($bundle->rows('folio_transactions') as $row) {
            $figure = $this->leftOutFigure($row, $date, $index);

            if ($figure === null) {
                continue;
            }

            $at = CarbonImmutable::parse((string) $row['created_at']);

            if ($at->gte($window['opened']) && ($window['closed'] === null || $at->lt($window['closed']))) {
                $totals[$figure] = $totals[$figure]->plus((string) $row['amount']);
            }
        }

        return $totals;
    }

    /**
     * Lines that moved to another folio during the date's window. wastays counts the reversal as an adjustment and the
     * new line as room revenue or tax, and estatell keeps the line where it was, so the wastays figures lose them.
     *
     * @return array<string, BigDecimal> keyed room_revenue, tax_revenue and adjustments_total
     */
    private function movements(WastaysBundle $bundle, WastaysDay $day, string $date): array
    {
        $window = $day->window($date);
        $totals = ['room_revenue' => BigDecimal::zero(), 'tax_revenue' => BigDecimal::zero(), 'adjustments_total' => BigDecimal::zero()];

        if ($window === null) {
            return $totals;
        }

        foreach ($bundle->rows('folio_transactions') as $row) {
            if ((json_decode((string) ($row['metadata'] ?? ''), true)['posting_source'] ?? null) !== 'folio_movement') {
                continue;
            }

            $at = CarbonImmutable::parse((string) $row['created_at']);

            if ($at->lt($window['opened']) || ($window['closed'] !== null && $at->gte($window['closed']))) {
                continue;
            }

            $figure = match (true) {
                ($row['transaction_type'] ?? null) === 'adjustment' => 'adjustments_total',
                $row['category'] === 'accommodation' => 'room_revenue',
                $row['category'] === 'tax' => 'tax_revenue',
                default => null,
            };

            if ($figure !== null) {
                $totals[$figure] = $totals[$figure]->plus((string) $row['amount']);
            }
        }

        return $totals;
    }

    /**
     * The figure a line counts in when its summary leaves it out, for a line posted during the window of `$date`.
     *
     * @param  array<string, string|null>  $row
     */
    private function leftOutFigure(array $row, string $date, WastaysBookingIndex $index): ?string
    {
        $source = json_decode((string) ($row['metadata'] ?? ''), true)['posting_source'] ?? null;

        return match (true) {
            ($row['night_audit_id'] ?? null) !== null => null,
            $source === 'catch_up' && $row['category'] === 'accommodation' => 'room_revenue',
            $source === 'catch_up' && $row['category'] === 'tax' => 'tax_revenue',
            $source === 'early_departure' && $row['category'] === 'early_departure_charge' && $index->sameDayLeaver((string) $row['source_booking_id']) => 'room_revenue',
            $source === 'early_departure' && $row['category'] === 'tax' && ($row['posting_date'] ?? null) !== $date => 'tax_revenue',
            // A payment dated to an earlier, closed night. wastays leaves it out of that night's total and of the day it was taken.
            ($row['transaction_type'] ?? null) === 'payment' && ($row['reversal_of_transaction_id'] ?? null) === null && BigDecimal::of((string) $row['amount'])->isPositive()
                && ($row['posting_date'] ?? $date) < $date => 'payments_total',
            // A staff adjustment dated to an earlier, closed night.
            $source === 'staff' && in_array($row['category'], ['adjustment', 'correction'], true) && ($row['reversal_of_transaction_id'] ?? null) === null
                && ($row['posting_date'] ?? $date) < $date => 'adjustments_total',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public function estatell(Property $property, string $date): array
    {
        $day = fn () => FolioLine::query()->whereHas('folio', fn ($folios) => $folios->where('property_id', $property->id))->whereDate('posting_date', $date);
        // The wastays room revenue holds only the nights its audit posted: no-show charges have their own figure, and
        // early departure fees count nowhere but in tax.
        $rooms = array_map(fn (SystemTransactionCode $code): string => $code->value, array_filter(SystemTransactionCode::roomRevenueCases(), fn (SystemTransactionCode $code): bool => ! in_array($code, [SystemTransactionCode::NoShowRevenue, SystemTransactionCode::EarlyDepartureRevenue], true)));
        // wastays keeps security deposits in their own table, outside its payment and refund totals.
        $money = fn () => $day()->where('kind', TransactionKind::Payment)->whereDoesntHave('payment', fn ($payments) => $payments->where('purpose', PaymentPurpose::SecurityDeposit));
        $byCode = fn (array $keys) => $day()->where('kind', TransactionKind::Charge)->whereHas('transactionCode', fn ($codes) => $codes->whereIn('system_key', $keys));

        return array_map($this->decimal(...), [
            'room_revenue' => (string) $byCode($rooms)->whereNull('reversal_of_id')->sum('amount'),
            'tax_revenue' => (string) $day()->where('kind', TransactionKind::Tax)->whereNull('reversal_of_id')->sum('amount'),
            'payments_total' => (string) abs((float) $money()->where('amount', '<', 0)->sum('amount')),
            'refunds_total' => (string) $money()->where('amount', '>', 0)->sum('amount'),
            // wastays books every reversal of a charge or its tax as a correction, in the adjustments.
            'adjustments_total' => (string) $day()->where(fn ($lines) => $lines->where('kind', TransactionKind::Adjustment)
                ->orWhere(fn ($reversals) => $reversals->whereIn('kind', [TransactionKind::Charge, TransactionKind::Tax])->whereNotNull('reversal_of_id')))->sum('amount'),
            'no_show_charges' => (string) $byCode([SystemTransactionCode::NoShowRevenue->value])->sum('amount'),
        ]);
    }

    /**
     * The figures that differ.
     *
     * @param  array<string, string>  $wastays
     * @param  array<string, string>  $estatell
     * @return list<array{figure: string, wastays: string, estatell: string}>
     */
    public function differences(array $wastays, array $estatell): array
    {
        $differences = [];

        foreach (self::FIGURES as $figure) {
            if (! BigDecimal::of($wastays[$figure])->isEqualTo($estatell[$figure])) {
                $differences[] = ['figure' => $figure, 'wastays' => $wastays[$figure], 'estatell' => $estatell[$figure]];
            }
        }

        return $differences;
    }

    private function decimal(string $value): string
    {
        return (string) BigDecimal::of($value === '' ? '0' : $value)->toScale(2, RoundingMode::HalfUp);
    }
}
