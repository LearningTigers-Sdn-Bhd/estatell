<?php

namespace App\Services\Folio;

use App\Enums\TransactionKind;
use App\Models\PrintSetting;
use Brick\Math\BigDecimal;

/**
 * Turns folio rows into what a customer document prints: payments and reversal pairs dropped, lines grouped by
 * unit, equal nights merged and taxes summed. It reads nothing from the database. The booking summary, the folio
 * invoice and the AR invoice all use it. See docs/02-property/05-printing-settings.md#line-grouper.
 */
class DocumentLines
{
    /**
     * @param  list<DocumentRow>  $rows  In the order the document prints them. Units keep the order they first appear in.
     */
    public function group(array $rows, PrintSetting $settings): DocumentLineGroups
    {
        $rows = $this->withoutPaymentsAndReversals($rows);

        $taxRows = array_values(array_filter($rows, fn (DocumentRow $row): bool => $row->kind === TransactionKind::Tax));
        $chargeRows = array_values(array_filter($rows, fn (DocumentRow $row): bool => $row->kind !== TransactionKind::Tax));

        $subtotal = $this->sum($chargeRows);
        $taxes = $this->taxes($taxRows, $settings->tax_breakdown);
        $total = $subtotal->plus($this->sum($taxRows));

        return new DocumentLineGroups(
            groups: $settings->show_unit_on_lines
                ? $this->byUnit($chargeRows, $settings->nightly_breakdown)
                : $this->byDate($chargeRows, $settings->nightly_breakdown),
            taxes: $taxes,
            chargesSubtotal: (string) $subtotal->toScale(2),
            total: (string) $total->toScale(2),
            showRoomColumn: ! $settings->show_unit_on_lines && count(array_unique(array_filter(array_map(fn (DocumentRow $row): ?int => $row->unitId, $chargeRows)))) > 1,
        );
    }

    /**
     * A reversal and the line it reverses net to 0, so both go. A transfer is a reversal plus a copy, so the copy stays.
     *
     * @param  list<DocumentRow>  $rows
     * @return list<DocumentRow>
     */
    private function withoutPaymentsAndReversals(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn (DocumentRow $row): bool => $row->kind !== TransactionKind::Payment));
        $present = [];

        foreach ($rows as $row) {
            if ($row->lineId !== null) {
                $present[$row->lineId] = true;
            }
        }

        $dropped = [];

        foreach ($rows as $row) {
            if ($row->reversalOfId !== null && isset($present[$row->reversalOfId])) {
                $dropped[$row->reversalOfId] = true;
                $dropped[$row->lineId ?? 0] = true;
            }
        }

        return array_values(array_filter($rows, fn (DocumentRow $row): bool => $row->lineId === null || ! isset($dropped[$row->lineId])));
    }

    /**
     * @param  list<DocumentRow>  $rows
     * @return list<DocumentLineGroup>
     */
    private function byUnit(array $rows, bool $nightly): array
    {
        $units = [];
        $other = [];

        foreach ($rows as $row) {
            if ($row->unitId === null) {
                $other[] = $row;

                continue;
            }

            $units[$row->unitId][] = $row;
        }

        $groups = [];

        foreach ($units as $unitRows) {
            $groups[] = new DocumentLineGroup($unitRows[0]->unitLabel ?? __('Other charges'), $this->lines($unitRows, $nightly));
        }

        if ($other !== []) {
            $groups[] = new DocumentLineGroup(__('Other charges'), $this->lines($other, $nightly));
        }

        return $groups;
    }

    /**
     * @param  list<DocumentRow>  $rows
     * @return list<DocumentLineGroup>
     */
    private function byDate(array $rows, bool $nightly): array
    {
        if ($rows === []) {
            return [];
        }

        $indexed = array_map(null, array_keys($rows), $rows);
        usort($indexed, fn (array $a, array $b): int => $a[1]->date <=> $b[1]->date
            ?: strnatcasecmp($a[1]->room ?? '', $b[1]->room ?? '')
            ?: $a[0] <=> $b[0]);
        $rows = array_map(fn (array $pair): DocumentRow => $pair[1], $indexed);

        return [new DocumentLineGroup(null, $this->lines($rows, $nightly))];
    }

    /**
     * One row per line, or with the nightly breakdown off, equal rows on consecutive dates merged. Only nightly rows merge: rows of one
     * unit, kind, description, unit amount and quantity are equal; a price change or a gap in dates starts a new row.
     *
     * @param  list<DocumentRow>  $rows
     * @return list<DocumentLineRow>
     */
    private function lines(array $rows, bool $nightly): array
    {
        $result = [];
        /** @var array<string, int> $open The index in $result of the run still open for each key. */
        $open = [];

        foreach ($rows as $row) {
            if (! $row->nightly) {
                $result[] = new DocumentLineRow($row->description, $row->date, $row->date, 1, $row->unitAmount, (string) BigDecimal::of($row->amount)->toScale(2), $row->room);

                continue;
            }

            $key = implode('|', [$row->unitId ?? 0, $row->kind->value, $row->description, $row->unitAmount, $row->quantity]);
            $index = $open[$key] ?? null;

            if (! $nightly && $index !== null && $result[$index]->dateTo->addDay()->isSameDay($row->date)) {
                $last = $result[$index];
                $result[$index] = new DocumentLineRow(
                    $last->description,
                    $last->dateFrom,
                    $row->date,
                    $last->nights + 1,
                    $last->unitAmount,
                    (string) BigDecimal::of($last->amount)->plus($row->amount)->toScale(2),
                    $last->room,
                );

                continue;
            }

            $result[] = new DocumentLineRow($row->description, $row->date, $row->date, 1, $row->unitAmount, (string) BigDecimal::of($row->amount)->toScale(2), $row->room);
            $open[$key] = array_key_last($result);
        }

        return array_values($result);
    }

    /**
     * @param  list<DocumentRow>  $taxRows
     * @return list<DocumentTaxLine>
     */
    private function taxes(array $taxRows, bool $perTax): array
    {
        if ($taxRows === []) {
            return [];
        }

        if (! $perTax) {
            return [new DocumentTaxLine(__('Tax'), (string) $this->sum($taxRows)->toScale(2), $this->numbers($taxRows))];
        }

        $sums = [];

        foreach ($taxRows as $row) {
            $sums[$row->description] = ($sums[$row->description] ?? BigDecimal::zero())->plus($row->amount);
        }

        $lines = [];

        foreach ($sums as $label => $amount) {
            $lines[] = new DocumentTaxLine((string) $label, (string) $amount->toScale(2), $this->numbers(array_values(array_filter($taxRows, fn (DocumentRow $row): bool => $row->description === (string) $label))));
        }

        return $lines;
    }

    /**
     * @param  list<DocumentRow>  $taxRows
     * @return list<string>
     */
    private function numbers(array $taxRows): array
    {
        return array_values(array_unique(array_filter(array_map(fn (DocumentRow $row): ?string => $row->taxNumber, $taxRows), filled(...))));
    }

    /**
     * @param  list<DocumentRow>  $rows
     */
    private function sum(array $rows): BigDecimal
    {
        return array_reduce($rows, fn (BigDecimal $carry, DocumentRow $row): BigDecimal => $carry->plus($row->amount), BigDecimal::zero());
    }
}
