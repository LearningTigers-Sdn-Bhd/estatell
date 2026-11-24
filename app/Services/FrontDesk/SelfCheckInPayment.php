<?php

namespace App\Services\FrontDesk;

use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\ReservationUnit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Whether a unit may self check-in at the kiosk: its unit folio already holds payments covering what Collect now would take
 * (`CheckInCollection`: the Collect at check-in taxes plus the security deposit). Payments count by amount, because a deposit
 * taken before arrival is an Advance. Held money is Completed payments minus refunds (a void is a refund of the whole payment) on the unit's folios. When the deposit is not due (already held, or none set) a SecurityDeposit payment is not counted, so it cannot also
 * pay the taxes. It reads only. See docs/18-front-desk/01-check-in-and-check-out.md#self-check-in-payment.
 */
class SelfCheckInPayment
{
    public function __construct(private CheckInCollection $collection) {}

    /**
     * `$wholeStay` asks for the whole bill instead of Collect now's taxes, as a stay booked from a quote link pays up front.
     */
    public function isPaidEnough(ReservationUnit $unit, bool $wholeStay = false): bool
    {
        return BigDecimal::of($this->shortfall($unit, $wholeStay))->isZero();
    }

    /**
     * What is still unpaid of Collect now, as a decimal string with two places. `0.00` when it is covered. With
     * `$wholeStay` the unit's whole bill (every Scheduled row, taxes included) takes the place of the Collect now taxes,
     * and the deposit is still added. See docs/34-ai-assistant/03-places-and-routes.md#quote-links.
     */
    public function shortfall(ReservationUnit $unit, bool $wholeStay = false): string
    {
        $collection = $this->collection->for($unit);
        $taxes = BigDecimal::of($collection->taxesHidden ? '0' : $collection->taxesTotal);
        $charges = $wholeStay ? BigDecimal::max($taxes, BigDecimal::of($collection->billTotal)->plus($collection->billPaid)) : $taxes;
        $required = $charges->plus($collection->deposit ?? '0');

        if (! $required->isPositive()) {
            return '0.00';
        }

        $held = $this->held($unit, $collection->deposit === null);

        return (string) ($held->isLessThan($required) ? $required->minus($held) : BigDecimal::zero())->toScale(2, RoundingMode::Up);
    }

    private function held(ReservationUnit $unit, bool $withoutDeposits): BigDecimal
    {
        $payments = Payment::query()
            ->where('property_id', $unit->property_id)
            ->whereHas('folio', fn ($folios) => $folios
                ->where('reservation_unit_id', $unit->id)
                ->where('type', FolioType::Unit)
                ->where('status', '<>', FolioStatus::Voided))
            ->where('status', PaymentStatus::Completed)
            ->when($withoutDeposits, fn ($query) => $query->where('purpose', '<>', PaymentPurpose::SecurityDeposit));

        $paid = BigDecimal::of((string) (clone $payments)->whereNull('refund_of_id')->sum('amount'));
        $refunded = BigDecimal::of((string) (clone $payments)->whereNotNull('refund_of_id')->sum('amount'));

        return $paid->minus($refunded);
    }
}
