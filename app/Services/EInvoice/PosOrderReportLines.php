<?php

namespace App\Services\EInvoice;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Enums\PosOrderType;
use App\Enums\TaxRateType;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\PosOrderLineTax;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What a walk-in POS order reports to LHDN: its standing lines' charges before tax (service charge included) and their
 * taxes by LHDN tax type, scaled to the walk-in share when part of the bill went to a room folio (the folio e-invoice
 * reports that part). A refund order stores positive amounts and is reported negative. Cash rounding is not reported.
 * It reads only.
 */
class PosOrderReportLines
{
    /**
     * The walk-in share of the bill: what was not charged to a room.
     */
    public function share(PosOrder $order): BigDecimal
    {
        return BigDecimal::of($order->grand_total)->minus($order->folio_posted_amount)->toScale(2);
    }

    /**
     * Whether the order may be reported on its own: settled and with a positive walk-in share.
     */
    public function reportable(PosOrder $order): bool
    {
        return $order->status === PosOrderStatus::Settled && $this->share($order)->isPositive();
    }

    /**
     * Whether the walk-in share is over LHDN's limit for a sale in a monthly summary.
     */
    public function isOverLimit(PosOrder $order): bool
    {
        return $this->share($order)->isGreaterThan(StaysNeedingOwnEInvoice::LIMIT);
    }

    /**
     * @return array{net: BigDecimal, tax: BigDecimal, taxes: array<string, BigDecimal>, taxable: array<string, BigDecimal>, percent: array<string, BigDecimal|null>, untaxed: BigDecimal}
     */
    public function split(PosOrder $order): array
    {
        $order->loadMissing('lines.taxes');
        $lines = $order->lines->filter(fn (PosOrderLine $line): bool => $line->kitchen_status !== PosKitchenStatus::Voided);

        $grand = BigDecimal::of($order->grand_total);
        $ratio = $grand->isPositive() ? $this->share($order)->dividedBy($grand, 8, RoundingMode::HalfUp) : BigDecimal::one();
        $sign = $order->order_type === PosOrderType::Refund ? BigDecimal::of(-1) : BigDecimal::one();
        $scale = fn (BigDecimal|string $amount): BigDecimal => BigDecimal::of($amount)->multipliedBy($ratio)->multipliedBy($sign)->toScale(2, RoundingMode::HalfUp);

        $net = BigDecimal::zero();
        $taxed = BigDecimal::zero();
        $taxes = [];
        $taxable = [];
        $percent = [];

        foreach ($lines as $line) {
            $lineNet = $scale(BigDecimal::of($line->net_amount)->plus($line->service_charge_amount));
            $net = $net->plus($lineNet);
            $lineTaxed = false;

            /** @var PosOrderLineTax $tax */
            foreach ($line->taxes as $tax) {
                $type = $tax->lhdn_tax_type ?? LhdnTaxType::SERVICE_TAX;
                $taxes[$type] = ($taxes[$type] ?? BigDecimal::zero())->plus($scale($tax->tax_amount));
                $taxable[$type] = ($taxable[$type] ?? BigDecimal::zero())->plus($scale($tax->taxable_base));
                $percent[$type] ??= $tax->rate_type === TaxRateType::Percent ? BigDecimal::of($tax->tax_rate)->toScale(2) : null;
                $lineTaxed = true;
            }

            if ($lineTaxed) {
                $taxed = $taxed->plus($lineNet);
            }
        }

        $tax = array_reduce($taxes, fn (BigDecimal $sum, BigDecimal $amount): BigDecimal => $sum->plus($amount), BigDecimal::zero());

        return [
            'net' => $net,
            'tax' => $tax,
            'taxes' => $taxes,
            'taxable' => $taxable,
            'percent' => $percent,
            'untaxed' => $net->minus($taxed),
        ];
    }
}
