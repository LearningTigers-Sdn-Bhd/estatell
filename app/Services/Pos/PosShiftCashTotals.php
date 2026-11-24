<?php

namespace App\Services\Pos;

use App\Enums\PosCashMovementKind;
use App\Enums\PosPaymentDirection;
use App\Enums\PosPaymentStatus;
use App\Enums\PosTenderType;
use App\Models\PosCashMovement;
use App\Models\PosOrderPayment;
use App\Models\PosShift;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The cash a shift should hold, worked out from its payments and cash movements:
 * `expected = opening cash + cash payments - cash refunds + paid in - paid out`. Cash payments and refunds count the
 * tendered amount plus the BNM rounding adjustment, captured rows only, so a swapped or voided tender never counts twice.
 * Float in is paid in; safe drops and payouts are paid out; a no-sale opening moves nothing. It reads only.
 */
class PosShiftCashTotals
{
    /**
     * @return array{cash_payments: string, cash_refunds: string, paid_in: string, paid_out: string, expected_cash: string}
     */
    public function for(PosShift $shift): array
    {
        $cash = fn (PosPaymentDirection $direction): BigDecimal => $this->sum(PosOrderPayment::query()
            ->where('pos_shift_id', $shift->id)
            ->where('direction', $direction->value)
            ->where('tender_type', PosTenderType::Cash->value)
            ->where('status', PosPaymentStatus::Captured->value)
            ->selectRaw('coalesce(sum(amount + rounding_adjustment), 0) as total')
            ->value('total'));

        $movement = fn (array $kinds): BigDecimal => $this->sum(PosCashMovement::query()
            ->where('pos_shift_id', $shift->id)
            ->whereIn('kind', array_map(fn (PosCashMovementKind $kind): string => $kind->value, $kinds))
            ->sum('amount'));

        $payments = $cash(PosPaymentDirection::In);
        $refunds = $cash(PosPaymentDirection::Out);
        $paidIn = $movement([PosCashMovementKind::FloatIn]);
        $paidOut = $movement([PosCashMovementKind::SafeDrop, PosCashMovementKind::Payout]);
        $expected = BigDecimal::of($shift->opening_cash)->plus($payments)->minus($refunds)->plus($paidIn)->minus($paidOut);

        return [
            'cash_payments' => $this->money($payments),
            'cash_refunds' => $this->money($refunds),
            'paid_in' => $this->money($paidIn),
            'paid_out' => $this->money($paidOut),
            'expected_cash' => $this->money($expected),
        ];
    }

    private function sum(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) $value);
    }

    private function money(BigDecimal $amount): string
    {
        return $amount->toScale(2, RoundingMode::HalfUp)->__toString();
    }
}
