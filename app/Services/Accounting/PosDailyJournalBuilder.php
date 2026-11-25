<?php

namespace App\Services\Accounting;

use App\Enums\PosCashMovementKind;
use App\Enums\PosOrderStatus;
use App\Enums\PosOrderType;
use App\Enums\PosPaymentDirection;
use App\Enums\PosPaymentStatus;
use App\Enums\PosTenderType;
use App\Models\BusinessDate;
use App\Models\PosCashMovement;
use App\Models\PosOrder;
use App\Models\PosOutlet;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Services\Pos\PosAmountAllocator;
use Brick\Math\BigDecimal;
use LogicException;

/**
 * The POS part of one business date's journal, as rows by transaction code with the net sign of the folio rows
 * (positive credits, negative debits). It is double entry on its own: the rows add up to zero, so a POS error never lands in
 * the guest ledger, and a cafe with no room activity still has a journal. It reads recorded POS activity whether or not
 * POS is still on, so turning it off never loses a past day.
 *
 * - Walk-in sale: debit the payment method clearing for what was paid (with the cash rounding), credit each revenue, service
 *   charge, packaging and tax code with the walk-in share of the bill, in whole sen. The room-charged share is the folio's.
 *   For a late-synced cash sale (`synced_late = true`), cash was physically counted in the prior shift (creating an overage in
 *   cash variance), so debit cash variance instead of cash clearing to offset that overage without creating a shortage on the
 *   current shift.
 * - Walk-in refund: the same in reverse, with the rounding sign inverted.
 * - Cash shortage or overage on shifts closed on the date: against the outlet's cash variance code and cash clearing.
 * - Payout, safe drop and float in: against the movement's counter code and cash clearing. A no-sale opening moves nothing.
 *
 * Throws `AccountingException` when an outlet has no cash payment method to book cash against. See docs/34-native-pos/05-shifts-audit-and-einvoice.md.
 */
class PosDailyJournalBuilder
{
    public function __construct(private PosAmountAllocator $allocator) {}

    /**
     * @return list<array{code: string, name: string, account: string|null, net: BigDecimal}>
     *
     * @throws AccountingException
     */
    public function rows(Property $property, BusinessDate $date): array
    {
        $nets = [];

        $this->orders($property, $date, $nets);
        $this->shifts($property, $date, $nets);
        $this->movements($property, $date, $nets);

        if ($nets === []) {
            return [];
        }

        $balance = array_reduce($nets, fn (BigDecimal $sum, BigDecimal $net): BigDecimal => $sum->plus($net), BigDecimal::zero());

        if (! $balance->isZero()) {
            throw new LogicException('The POS journal of '.$date->date->toDateString().' does not balance by '.$balance->__toString().'.');
        }

        $codes = TransactionCode::query()->whereIn('id', array_keys($nets))->get()->keyBy('id');
        $rows = [];

        foreach ($nets as $id => $net) {
            if ($net->isZero()) {
                continue;
            }

            $code = $codes[$id];
            $account = $code->gl_account_code;
            $rows[] = [
                'code' => $code->code,
                'name' => $code->name,
                'account' => is_string($account) && trim($account) !== '' ? trim($account) : null,
                'net' => $net->toScale(2),
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $rows;
    }

    /**
     * @param  array<int, BigDecimal>  $nets
     */
    private function orders(Property $property, BusinessDate $date, array &$nets): void
    {
        $orders = PosOrder::query()
            ->where('property_id', $property->id)
            ->whereDate('business_date', $date->date->toDateString())
            ->where('status', PosOrderStatus::Settled->value)
            ->with(['payments' => fn ($query) => $query->where('status', PosPaymentStatus::Captured->value), 'payments.paymentMethod', 'lines.taxes', 'outlet'])
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            $sign = $order->order_type === PosOrderType::Refund ? -1 : 1;
            $walkIn = BigDecimal::of($order->grand_total)->minus($order->folio_posted_amount);

            if ($walkIn->isPositive()) {
                $this->buckets($order, $walkIn, $sign, $nets);
            }

            foreach ($order->payments as $payment) {
                if ($payment->tender_type === PosTenderType::RoomCharge) {
                    continue;
                }

                $clearing = $order->synced_late && $payment->tender_type === PosTenderType::Cash
                    ? ($order->outlet->cash_variance_transaction_code_id
                        ?? throw AccountingException::unmappedCodes(['cash variance of '.$order->outlet->name]))
                    : ($payment->paymentMethod->transaction_code_id
                        ?? throw AccountingException::unmappedCodes(['payment method of order '.$order->order_number]));
                $direction = $payment->direction === PosPaymentDirection::In ? 1 : -1;
                $this->add($nets, $clearing, BigDecimal::of($payment->amount)->plus($payment->rounding_adjustment)->multipliedBy($direction)->negated());

                if (! BigDecimal::of($payment->rounding_adjustment)->isZero()) {
                    $rounding = $order->outlet->rounding_transaction_code_id ?? throw AccountingException::unmappedCodes(['rounding of '.$order->outlet->name]);
                    $this->add($nets, $rounding, BigDecimal::of($payment->rounding_adjustment)->multipliedBy($direction));
                }
            }
        }
    }

    /**
     * Credit (a sale) or debit (a refund) the order's revenue, service charge and tax buckets with the walk-in share.
     *
     * @param  array<int, BigDecimal>  $nets
     */
    private function buckets(PosOrder $order, BigDecimal $walkIn, int $sign, array &$nets): void
    {
        $components = [];

        foreach ($order->lines as $line) {
            $components[] = [$line->transaction_code_id, $line->net_amount];

            if (BigDecimal::of($line->service_charge_amount)->isPositive()) {
                $components[] = [$order->outlet->service_charge_transaction_code_id ?? throw AccountingException::unmappedCodes(['service charge of '.$order->outlet->name]), $line->service_charge_amount];
            }

            foreach ($line->taxes as $tax) {
                $components[] = [$tax->transaction_code_id, $tax->tax_amount];
            }
        }

        $shares = $this->allocator->allocate($walkIn->toScale(2)->__toString(), array_map(fn (array $component): string => $component[1], $components));

        foreach ($components as $index => [$code, $amount]) {
            $this->add($nets, $code, BigDecimal::of($shares[$index])->multipliedBy($sign));
        }
    }

    /**
     * @param  array<int, BigDecimal>  $nets
     */
    private function shifts(Property $property, BusinessDate $date, array &$nets): void
    {
        $shifts = PosShift::query()
            ->where('property_id', $property->id)
            ->whereDate('closed_business_date', $date->date->toDateString())
            ->whereNotNull('cash_variance')
            ->where('cash_variance', '!=', 0)
            ->with('outlet')
            ->orderBy('id')
            ->get();

        foreach ($shifts as $shift) {
            $variance = BigDecimal::of($shift->cash_variance);
            $variancePocket = $shift->outlet->cash_variance_transaction_code_id ?? throw AccountingException::unmappedCodes(['cash variance of '.$shift->outlet->name]);
            $cash = $this->cashClearing($shift->outlet);

            // A shortage (negative variance) debits the variance code and credits cash; an overage does the opposite.
            $this->add($nets, $variancePocket, $variance);
            $this->add($nets, $cash, $variance->negated());
        }
    }

    /**
     * @param  array<int, BigDecimal>  $nets
     */
    private function movements(Property $property, BusinessDate $date, array &$nets): void
    {
        $movements = PosCashMovement::query()
            ->where('property_id', $property->id)
            ->whereDate('business_date', $date->date->toDateString())
            ->where('kind', '!=', PosCashMovementKind::NoSaleOpen->value)
            ->with('shift.outlet')
            ->orderBy('id')
            ->get();

        foreach ($movements as $movement) {
            $amount = BigDecimal::of($movement->amount);
            $cash = $this->cashClearing($movement->shift->outlet);
            $counter = $movement->counter_transaction_code_id ?? throw AccountingException::unmappedCodes(['counter code of a cash movement']);

            if ($movement->kind === PosCashMovementKind::FloatIn) {
                $this->add($nets, $cash, $amount->negated());
                $this->add($nets, $counter, $amount);
            } else {
                $this->add($nets, $counter, $amount->negated());
                $this->add($nets, $cash, $amount);
            }
        }
    }

    private function cashClearing(PosOutlet $outlet): int
    {
        return $outlet->paymentMethods()->where('tender_type', PosTenderType::Cash->value)->where('active', true)->orderBy('sort_order')->orderBy('id')->with('paymentMethod')->first()?->paymentMethod->transaction_code_id
            ?? throw AccountingException::unmappedCodes(['cash clearing of '.$outlet->name]);
    }

    /**
     * @param  array<int, BigDecimal>  $nets
     */
    private function add(array &$nets, int $codeId, BigDecimal $amount): void
    {
        $nets[$codeId] = ($nets[$codeId] ?? BigDecimal::zero())->plus($amount);
    }
}
