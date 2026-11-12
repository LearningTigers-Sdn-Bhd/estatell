<?php

namespace App\Services\EInvoice;

use App\Enums\EInvoiceDocumentType;
use App\Models\EInvoiceSubmission;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * How a credit or refund note takes its amount off an e-invoice, read from what LHDN received for the e-invoice and
 * for its earlier notes. What is left falls in three parts:
 *
 * - **SST**, by rate: the charges Service Tax is on, and that tax. A part credit takes SST off in proportion, so the
 *   note shows the rate and the SST it adjusts, as Service Tax Act 2018 section 23 and its regulations ask of a
 *   credit note.
 * - **Untaxed**: the charges with no tax on them.
 * - **Levies**: tourism tax and other fixed levies (`03`, `06`, ...). They are charged per room-night, not on the
 *   price, so a part credit leaves them; they come off only when the whole remaining amount is credited.
 *
 * Amounts are tax included, as staff enter them. It reads only. See
 * docs/31-einvoice/01-lhdn-myinvois.md#credit-and-refund-notes.
 */
class CreditNoteSplit
{
    /**
     * What is left to credit on the e-invoice.
     *
     * @return array{sst: array<string, array{net: BigDecimal, tax: BigDecimal, percent: BigDecimal}>, untaxed: BigDecimal, levies: array<string, BigDecimal>}
     */
    public function remaining(EInvoiceSubmission $original): array
    {
        $left = $this->parts($original->document_payload);

        $notes = $original->adjustments()
            ->whereIn('document_type', [EInvoiceDocumentType::CreditNote, EInvoiceDocumentType::RefundNote])
            ->active()
            ->get(['id', 'document_payload']);

        foreach ($notes as $note) {
            $taken = $this->parts($note->document_payload);

            foreach ($taken['sst'] as $rate => $part) {
                $left['sst'][$rate] ??= ['net' => BigDecimal::zero(), 'tax' => BigDecimal::zero(), 'percent' => $part['percent']];
                $left['sst'][$rate]['net'] = $left['sst'][$rate]['net']->minus($part['net']);
                $left['sst'][$rate]['tax'] = $left['sst'][$rate]['tax']->minus($part['tax']);
            }
            $left['untaxed'] = $left['untaxed']->minus($taken['untaxed']);
            foreach ($taken['levies'] as $type => $amount) {
                $left['levies'][$type] = ($left['levies'][$type] ?? BigDecimal::zero())->minus($amount);
            }
        }

        return $this->withoutShortfall($left);
    }

    /**
     * What a part credit may take off: everything left but the levies.
     */
    public function creditable(EInvoiceSubmission $original): BigDecimal
    {
        return $this->creditableOf($this->remaining($original));
    }

    /**
     * Everything left, levies included.
     */
    public function total(EInvoiceSubmission $original): BigDecimal
    {
        $remaining = $this->remaining($original);

        return $this->creditableOf($remaining)->plus($this->sum($remaining['levies']));
    }

    /**
     * Split `$amount` (tax included) over what is left. Null when it is more than a part credit may take and not the
     * whole remaining amount.
     *
     * @return array{sst: array<string, array{net: BigDecimal, tax: BigDecimal, percent: BigDecimal}>, untaxed: BigDecimal, levies: array<string, BigDecimal>}|null
     */
    public function split(EInvoiceSubmission $original, string $amount): ?array
    {
        $remaining = $this->remaining($original);
        $credit = BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
        $creditable = $this->creditableOf($remaining);
        $levies = $this->sum($remaining['levies']);

        // The whole remaining amount: everything left, exactly.
        if ($credit->isEqualTo($creditable->plus($levies)) && $levies->isPositive()) {
            return $remaining;
        }

        // All but the levies: every part exactly, so nothing is left over by rounding.
        if ($credit->isEqualTo($creditable)) {
            return [...$remaining, 'levies' => []];
        }

        if (! $credit->isPositive() || $credit->isGreaterThan($creditable)) {
            return null;
        }

        return $this->proportional($remaining, $credit, $creditable);
    }

    /**
     * Each part takes its share of the credit; within an SST part the tax takes its share of that part. The last
     * part takes what rounding left, so the parts add up to the credit to the sen.
     *
     * @param  array{sst: array<string, array{net: BigDecimal, tax: BigDecimal, percent: BigDecimal}>, untaxed: BigDecimal, levies: array<string, BigDecimal>}  $remaining
     * @return array{sst: array<string, array{net: BigDecimal, tax: BigDecimal, percent: BigDecimal}>, untaxed: BigDecimal, levies: array<string, BigDecimal>}
     */
    private function proportional(array $remaining, BigDecimal $credit, BigDecimal $creditable): array
    {
        $parts = [];
        foreach ($remaining['sst'] as $rate => $part) {
            $parts[] = ['rate' => (string) $rate, 'gross' => $part['net']->plus($part['tax'])];
        }
        $parts[] = ['rate' => null, 'gross' => $remaining['untaxed']];
        $parts = array_values(array_filter($parts, fn (array $part): bool => $part['gross']->isPositive()));

        $split = ['sst' => [], 'untaxed' => BigDecimal::zero(), 'levies' => []];
        $given = BigDecimal::zero();

        foreach ($parts as $index => $part) {
            $share = $index === count($parts) - 1
                ? $credit->minus($given)
                : $credit->multipliedBy($part['gross'])->dividedBy($creditable, 2, RoundingMode::HalfUp);
            $given = $given->plus($share);

            if ($part['rate'] === null) {
                $split['untaxed'] = $share;

                continue;
            }

            $sst = $remaining['sst'][$part['rate']];
            $tax = $share->multipliedBy($sst['tax'])->dividedBy($part['gross'], 2, RoundingMode::HalfUp);
            $split['sst'][$part['rate']] = ['net' => $share->minus($tax), 'tax' => $tax, 'percent' => $sst['percent']];
        }

        return $split;
    }

    /**
     * The parts of one stored document, by its lines: a line's Service Tax is on the charges its subtotal names, the
     * rest of the line is untaxed, and every other tax is a levy. The rate is the line's `Percent`, or for a document
     * sent before it carried one, the tax over the taxable amount to the whole percent.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{sst: array<string, array{net: BigDecimal, tax: BigDecimal, percent: BigDecimal}>, untaxed: BigDecimal, levies: array<string, BigDecimal>}
     */
    private function parts(?array $payload): array
    {
        $document = data_get($payload, 'Invoice.0') ?? data_get($payload, 'CreditNote.0') ?? [];
        $parts = ['sst' => [], 'untaxed' => BigDecimal::zero(), 'levies' => []];

        foreach (data_get($document, 'InvoiceLine', []) as $line) {
            $net = $this->amount(data_get($line, 'LineExtensionAmount.0._'));
            $sstTaxable = BigDecimal::zero();

            foreach (data_get($line, 'TaxTotal.0.TaxSubtotal', []) as $subtotal) {
                $type = (string) data_get($subtotal, 'TaxCategory.0.ID.0._');
                $taxable = $this->amount(data_get($subtotal, 'TaxableAmount.0._'));
                $tax = $this->amount(data_get($subtotal, 'TaxAmount.0._'));

                if ($type === LhdnTaxType::SERVICE_TAX) {
                    $percent = data_get($subtotal, 'Percent.0._') !== null
                        ? $this->amount(data_get($subtotal, 'Percent.0._'))
                        : ($taxable->isZero() ? BigDecimal::zero() : $tax->multipliedBy(100)->dividedBy($taxable, 0, RoundingMode::HalfUp)->toScale(2));
                    $key = (string) $percent;
                    $parts['sst'][$key] ??= ['net' => BigDecimal::zero(), 'tax' => BigDecimal::zero(), 'percent' => $percent];
                    $parts['sst'][$key]['net'] = $parts['sst'][$key]['net']->plus($taxable);
                    $parts['sst'][$key]['tax'] = $parts['sst'][$key]['tax']->plus($tax);
                    $sstTaxable = $sstTaxable->plus($taxable);
                } elseif ($type !== 'E' && $tax->isPositive()) {
                    $parts['levies'][$type] = ($parts['levies'][$type] ?? BigDecimal::zero())->plus($tax);
                }
            }

            if ($net->isGreaterThan($sstTaxable)) {
                $parts['untaxed'] = $parts['untaxed']->plus($net->minus($sstTaxable));
            }
        }

        return $parts;
    }

    /**
     * Earlier notes sent before notes split SST took their whole amount as untaxed. When that takes the untaxed part
     * below zero, the shortfall comes off the SST parts in proportion, so nothing is credited twice.
     *
     * @param  array{sst: array<string, array{net: BigDecimal, tax: BigDecimal, percent: BigDecimal}>, untaxed: BigDecimal, levies: array<string, BigDecimal>}  $left
     * @return array{sst: array<string, array{net: BigDecimal, tax: BigDecimal, percent: BigDecimal}>, untaxed: BigDecimal, levies: array<string, BigDecimal>}
     */
    private function withoutShortfall(array $left): array
    {
        $left['levies'] = array_filter($left['levies'], fn (BigDecimal $amount): bool => $amount->isPositive());

        if (! $left['untaxed']->isNegative()) {
            return $left;
        }

        $shortfall = $left['untaxed']->negated();
        $left['untaxed'] = BigDecimal::zero();
        $sstGross = BigDecimal::zero();
        foreach ($left['sst'] as $part) {
            $sstGross = $sstGross->plus($part['net'])->plus($part['tax']);
        }

        if (! $sstGross->isPositive()) {
            return [...$left, 'sst' => []];
        }

        $keep = BigDecimal::one()->minus($shortfall->dividedBy($sstGross, 10, RoundingMode::HalfUp));
        foreach ($left['sst'] as $rate => $part) {
            $left['sst'][$rate]['net'] = $keep->isNegative() ? BigDecimal::zero() : $part['net']->multipliedBy($keep)->toScale(2, RoundingMode::HalfUp);
            $left['sst'][$rate]['tax'] = $keep->isNegative() ? BigDecimal::zero() : $part['tax']->multipliedBy($keep)->toScale(2, RoundingMode::HalfUp);
        }

        return $left;
    }

    /**
     * @param  array{sst: array<string, array{net: BigDecimal, tax: BigDecimal, percent: BigDecimal}>, untaxed: BigDecimal, levies: array<string, BigDecimal>}  $remaining
     */
    private function creditableOf(array $remaining): BigDecimal
    {
        $sum = $remaining['untaxed'];
        foreach ($remaining['sst'] as $part) {
            $sum = $sum->plus($part['net'])->plus($part['tax']);
        }

        return $sum->toScale(2);
    }

    /**
     * @param  array<string, BigDecimal>  $amounts
     */
    private function sum(array $amounts): BigDecimal
    {
        return array_reduce($amounts, fn (BigDecimal $sum, BigDecimal $amount): BigDecimal => $sum->plus($amount), BigDecimal::zero())->toScale(2);
    }

    private function amount(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? 0))->toScale(2, RoundingMode::HalfUp);
    }
}
