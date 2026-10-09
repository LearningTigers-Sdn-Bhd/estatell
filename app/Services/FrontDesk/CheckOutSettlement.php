<?php

namespace App\Services\FrontDesk;

use App\Data\Reservations\UnitCheckOutPreviewData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\ReservationUnit;
use App\Services\Folio\FolioBalance;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * What stands between a unit and check-out: the balance of its own folio and the security deposit it holds. It
 * reads only. A held deposit is a credit, so the folio reaches 0 only after the deposit is refunded or used against
 * charges. See docs/18-front-desk/01-check-in-and-check-out.md#check-out.
 */
class CheckOutSettlement
{
    public function __construct(private FolioBalance $folioBalance) {}

    public function for(ReservationUnit $unit, bool $canRefund, bool $canCollect = false): UnitCheckOutPreviewData
    {
        $folios = $this->folios($unit);
        $balance = $folios->reduce(fn (BigDecimal $sum, Folio $folio): BigDecimal => $sum->plus($this->folioBalance->forFolio($folio)), BigDecimal::zero());
        $deposits = $this->heldDeposits($folios);
        $held = $deposits->reduce(fn (BigDecimal $sum, Payment $payment): BigDecimal => $sum->plus($this->refundable($payment)), BigDecimal::zero());
        $credit = $balance->isNegative() ? $balance->negated() : BigDecimal::zero();

        return new UnitCheckOutPreviewData(
            balance: (string) $balance->toScale(2),
            owing: (string) ($balance->isPositive() ? $balance : BigDecimal::zero())->toScale(2),
            depositHeld: (string) $held->toScale(2),
            refundDefault: (string) ($credit->isLessThan($held) ? $credit : $held)->toScale(2),
            depositMethod: $deposits->first()?->paymentMethod->name,
            canRefund: $canRefund,
            folioKey: $folios->first() === null ? null : (string) $folios->first()->id,
            error: null,
            canCollect: $canCollect,
        );
    }

    /**
     * The completed security deposit payments of the unit's folios that still have money to give back, oldest first.
     *
     * @return Collection<int, Payment>
     */
    public function heldDeposits(Collection $folios): Collection
    {
        return Payment::query()
            ->whereIn('folio_id', $folios->pluck('id'))
            ->whereNull('refund_of_id')
            ->where('purpose', PaymentPurpose::SecurityDeposit)
            ->where('status', PaymentStatus::Completed)
            ->withSum('refunds', 'amount')
            ->with('paymentMethod')
            ->orderBy('id')
            ->get()
            ->filter(fn (Payment $payment): bool => $this->refundable($payment)->isPositive())
            ->values();
    }

    /**
     * @return Collection<int, Folio>
     */
    public function folios(ReservationUnit $unit): Collection
    {
        return Folio::query()
            ->where('reservation_unit_id', $unit->id)
            ->where('type', FolioType::Unit)
            ->where('status', '!=', FolioStatus::Voided)
            ->orderBy('id')
            ->get();
    }

    public function refundable(Payment $payment): BigDecimal
    {
        return BigDecimal::of($payment->amount)->minus((string) ($payment->refunds_sum_amount ?? '0'));
    }
}
