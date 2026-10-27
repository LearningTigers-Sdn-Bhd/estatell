<?php

namespace App\Services\Reports;

use App\Data\Reports\DailyChargeRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Data\Shared\BadgeData;
use App\Enums\ChargeBucket;
use App\Enums\ChargeStatus;
use App\Enums\TransactionKind;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Tax;
use App\Services\Reservations\ReservationRoomBox;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Daily report › Charges, the charge register: Revenue at guest level. A row is an owner line and a posting date, and
 * every non-payment line of the range belongs to exactly one row, on its own posting date, so with no filter the totals
 * equal Daily report › Revenue on every date. The lines come from `DailyRevenueQuery::ledgerLinesBetween()`, so the
 * filter (no migrated lines, no Opening balance) is the same.
 *
 * The owner of a line: a line with no parent is its own owner, unless it reverses a child (then it belongs to the owner
 * of the reversed line); a line with a parent belongs to the owner of its parent; an Adjustment child that has Tax
 * lines of its own is a later discount and is its own owner. A reversal of an owner line is its own owner. The walk
 * stops after four steps and a line is never dropped. Owners and reversed lines outside the range are loaded by id with
 * no migrated filter, a round for each step, then the relations of all lines are loaded once, so the number of queries
 * does not depend on the number of lines.
 *
 * Base is the owner line on its own date, Discount the other Adjustment lines of the row, and each tax column the Tax
 * lines of that code. A tax line whose code has no tax is left out. `bucket` and `search` filter the rows after the
 * owners are found, so they also filter the totals. See docs/22-reports/02-guest-list-and-daily-report.md#charges.
 */
class DailyChargesReport implements HasTotals, Report
{
    private const int MAX_OWNER_STEPS = 4;

    private const string HAS_TAX_CHILDREN = 'exists (select 1 from folio_lines as tax_children where tax_children.parent_line_id = folio_lines.id and tax_children.kind = ?) as has_tax_children';

    public function __construct(
        private DailyRevenueQuery $revenue,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<DailyChargeRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $taxCodes = Tax::query()->where('property_id', $property->id)->orderBy('id')->pluck('code', 'transaction_code_id')->all();
        $range = $this->revenue
            ->ledgerLinesBetween($property, CarbonImmutable::parse($filters->from), CarbonImmutable::parse($filters->to))
            ->whereIn('folio_lines.kind', [TransactionKind::Charge->value, TransactionKind::Tax->value, TransactionKind::Adjustment->value])
            ->select('folio_lines.*')
            ->selectRaw(self::HAS_TAX_CHILDREN, [TransactionKind::Tax->value])
            ->orderBy('folio_lines.posting_date')
            ->orderBy('folio_lines.id')
            ->get();

        $known = $this->withOwners($property, $range->keyBy('id')->all());
        (new EloquentCollection($known))->load(['folio.reservation.booker', 'folio.payerGuest', 'folio.payerCompany', 'transactionCode', 'user']);

        /** @var array<string, array{owner: FolioLine, lines: non-empty-list<FolioLine>}> $groups */
        $groups = [];

        foreach ($range as $line) {
            if ($line->kind === TransactionKind::Tax && ! isset($taxCodes[$line->transaction_code_id])) {
                continue;
            }

            $owner = $this->ownerOf($line, $known);
            $group = $groups["{$owner->id}|{$line->posting_date->toDateString()}"] ?? ['owner' => $owner, 'lines' => []];
            $group['lines'][] = $line;
            $groups["{$owner->id}|{$line->posting_date->toDateString()}"] = $group;
        }

        $unitIds = array_filter(array_map(fn (array $group): ?int => $group['owner']->folio->reservation_unit_id, $groups));

        $rooms = $this->roomBox->assignmentsForUnits(array_values(array_unique($unitIds)));
        $zeroTaxes = array_fill_keys(array_values($taxCodes), BigDecimal::zero());
        $search = $filters->search === null ? null : mb_strtolower($filters->search);
        $keys = $rowKeys === null ? null : array_map('strval', $rowKeys);
        $rows = [];

        foreach ($groups as ['owner' => $owner, 'lines' => $lines]) {
            $bucket = ChargeBucket::forLine($owner->kind, $owner->transactionCode->category->value, $owner->transactionCode->system_key);

            if ($filters->bucket !== null && $bucket !== $filters->bucket) {
                continue;
            }

            $row = $this->row($lines, $owner, $bucket, $taxCodes, $zeroTaxes, $rooms);

            if ($search !== null && ! str_contains(mb_strtolower($row->booking), $search) && ! str_contains(mb_strtolower($row->guest), $search)) {
                continue;
            }

            if ($keys !== null && ! in_array((string) $row->key, $keys, true)) {
                continue;
            }

            $rows[] = $row;
        }

        usort($rows, fn (DailyChargeRow $a, DailyChargeRow $b): int => [$a->date, $a->key] <=> [$b->date, $b->key]);

        return $rows;
    }

    /**
     * The sums of the rows: Base, Discount, each tax and Total.
     *
     * @param  list<DailyChargeRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        $sum = fn (callable $amount): string => $this->format(array_reduce($rows, fn (BigDecimal $sum, DailyChargeRow $row): BigDecimal => $sum->plus($amount($row)), BigDecimal::zero()));
        $taxCodes = array_values(array_unique(array_merge([], ...array_map(fn (DailyChargeRow $row): array => array_keys($row->taxes), $rows))));
        $taxes = [];

        foreach ($taxCodes as $code) {
            $taxes["tax:{$code}"] = $sum(fn (DailyChargeRow $row): string => $row->taxes[$code] ?? '0');
        }

        return new ReportTotals([
            'date' => null,
            'charge' => null,
            'booking' => null,
            'guest' => null,
            'base' => $sum(fn (DailyChargeRow $row): string => $row->base),
            'discount' => $sum(fn (DailyChargeRow $row): string => $row->discount),
            ...$taxes,
            'total' => $sum(fn (DailyChargeRow $row): string => $row->total),
        ]);
    }

    /**
     * Loads, round by round, the parents and the reversed lines of the known lines that are not known yet, so every
     * owner is in the list. The ids of one round go in one query.
     *
     * @param  array<int, FolioLine>  $known
     * @return array<int, FolioLine>
     */
    private function withOwners(Property $property, array $known): array
    {
        for ($step = 0; $step < self::MAX_OWNER_STEPS; $step++) {
            $missing = [];

            foreach ($known as $line) {
                $next = $this->nextId($line, $known);

                if ($next !== null && ! isset($known[$next])) {
                    $missing[$next] = $next;
                }
            }

            if ($missing === []) {
                break;
            }

            $loaded = FolioLine::query()
                ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
                ->where('folios.property_id', $property->id)
                ->whereIn('folio_lines.id', array_values($missing))
                ->select('folio_lines.*')
                ->selectRaw(self::HAS_TAX_CHILDREN, [TransactionKind::Tax->value])
                ->get();

            foreach ($loaded as $line) {
                $known[$line->id] = $line;
            }
        }

        return $known;
    }

    /**
     * The owner of a line, found by walking up from it for at most four steps.
     *
     * @param  array<int, FolioLine>  $known
     */
    private function ownerOf(FolioLine $line, array $known): FolioLine
    {
        for ($step = 0; $step < self::MAX_OWNER_STEPS; $step++) {
            $next = $this->nextId($line, $known);

            if ($next === null || ! isset($known[$next])) {
                break;
            }

            $line = $known[$next];
        }

        return $line;
    }

    /**
     * The id of the line that owns this one one step up, or null when this line is its own owner.
     *
     * @param  array<int, FolioLine>  $known
     */
    private function nextId(FolioLine $line, array $known): ?int
    {
        if ($this->isLaterDiscount($line)) {
            return null;
        }

        if ($line->parent_line_id !== null) {
            return $line->parent_line_id;
        }

        if ($line->reversal_of_id === null || ! in_array($line->kind, [TransactionKind::Tax, TransactionKind::Adjustment], true)) {
            return null;
        }

        $reversed = $known[$line->reversal_of_id] ?? null;

        if ($reversed !== null && ($reversed->parent_line_id === null || $this->isLaterDiscount($reversed))) {
            return null;
        }

        return $line->reversal_of_id;
    }

    /**
     * An Adjustment child that has Tax lines of its own (`ApplyDiscount` posts them) is a later discount and a row of
     * its own.
     */
    private function isLaterDiscount(FolioLine $line): bool
    {
        return $line->kind === TransactionKind::Adjustment && $line->parent_line_id !== null && (bool) $line->getAttribute('has_tax_children');
    }

    /**
     * @param  non-empty-list<FolioLine>  $lines  The lines of the row, oldest first.
     * @param  array<int, string>  $taxCodes  The tax code by transaction code id.
     * @param  array<string, BigDecimal>  $zeroTaxes
     * @param  array<int, string>  $rooms  The room by reservation unit id.
     */
    private function row(array $lines, FolioLine $owner, ?ChargeBucket $bucket, array $taxCodes, array $zeroTaxes, array $rooms): DailyChargeRow
    {
        $base = BigDecimal::zero();
        $discount = BigDecimal::zero();
        $taxes = $zeroTaxes;
        $keyLine = $lines[0];

        foreach ($lines as $line) {
            $amount = BigDecimal::of($line->amount);

            if ($line->id === $owner->id) {
                $keyLine = $line;
            }

            if ($line->kind === TransactionKind::Tax) {
                $taxes[$taxCodes[$line->transaction_code_id]] = $taxes[$taxCodes[$line->transaction_code_id]]->plus($amount);
            } elseif ($line->kind === TransactionKind::Adjustment && $line->id !== $owner->id) {
                $discount = $discount->plus($amount);
            } else {
                $base = $base->plus($amount);
            }
        }

        $total = array_reduce($taxes, fn (BigDecimal $sum, BigDecimal $tax): BigDecimal => $sum->plus($tax), $base->plus($discount));
        $folio = $owner->folio;
        $reservation = $folio->reservation;

        return new DailyChargeRow(
            key: $keyLine->id,
            date: $lines[0]->posting_date->toDateString(),
            charge: $owner->description,
            booking: $reservation->reference,
            guest: $folio->payerCompany->name ?? $folio->payerGuest->full_name ?? $reservation->booker->full_name,
            base: $this->format($base),
            discount: $this->format($discount),
            taxes: array_map($this->format(...), $taxes),
            total: $this->format($total),
            bucket: $bucket?->label() ?? '',
            folio: $folio->number,
            room: $folio->reservation_unit_id === null ? '' : ($rooms[$folio->reservation_unit_id] ?? ''),
            code: $owner->transactionCode->code,
            status: BadgeData::fromChargeStatus(ChargeStatus::forLine($keyLine->transfer_group_id, $keyLine->reversal_of_id)),
            staff: $keyLine->user?->name,
        );
    }

    private function format(BigDecimal $amount): string
    {
        return (string) $amount->toScale(2, RoundingMode::HalfUp);
    }
}
