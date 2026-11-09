<?php

namespace App\Services\FrontDesk;

use App\Data\Folio\FolioRowData;
use App\Data\Reservations\CheckInCollectionData;
use App\Enums\BillingMode;
use App\Enums\FolioType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\ReservationUnit;
use App\Services\Catalogues\CheckInTaxRule;
use App\Services\Folio\CollectedTaxPayer;
use App\Services\Folio\FolioRouter;
use App\Services\Folio\ScheduledFolioRows;
use Brick\Math\BigDecimal;

/**
 * What Collect now asks the guest to pay at check-in, for one unit: the taxes flagged **Collect at check-in** and the
 * security deposit. It reads only. The tax amounts are the Scheduled tax rows of the unit's nights (what the night
 * audit will post), so the guest pays the same sum that lands on the folio. The check-in Action quotes it again on
 * submit and the form never sends an amount. See docs/18-front-desk/01-check-in-and-check-out.md#collect-now.
 */
class CheckInCollection
{
    public function __construct(
        private ScheduledFolioRows $scheduledRows,
        private FolioRouter $folioRouter,
        private CollectedTaxPayer $collectedTaxPayer,
        private CheckInTaxRule $checkInTaxRule,
    ) {}

    public function for(ReservationUnit $unit): CheckInCollectionData
    {
        $reservation = $unit->reservation()->with('booker')->firstOrFail();
        $property = $reservation->property()->with('settings')->firstOrFail();
        $flagged = $this->checkInTaxRule->flaggedTaxes($property);
        $master = $this->folioRouter->roomChargesMasterFolio($unit);
        $hidden = $master !== null && ! $this->collectedTaxPayer->guestPaysOnMaster($unit, $master);
        $taxes = [];
        $total = BigDecimal::zero();
        $blocks = array_values(array_filter(
            $this->scheduledRows->forReservation($property, $reservation, []),
            fn (array $block): bool => $block['unit'] === $unit->id,
        ));

        if ($flagged->isNotEmpty() && ! $hidden) {
            $sums = $this->taxSums($blocks, array_values(array_map(intval(...), $flagged->pluck('id')->all())));

            foreach ($flagged as $tax) {
                $amount = $sums[$tax->id] ?? BigDecimal::zero();

                if ($amount->isZero() && ! $tax->foreign_guests_only) {
                    continue;
                }

                $total = $total->plus($amount);
                $taxes[] = ['name' => $tax->name, 'amount' => (string) $amount->toScale(2), 'exempt' => $amount->isZero()];
            }
        }

        $bill = $this->billTotal($blocks);
        $paid = BigDecimal::min($bill, BigDecimal::max(BigDecimal::zero(), $this->paidOnUnitFolio($unit, $master)));

        return new CheckInCollectionData($taxes, (string) $total->toScale(2), $hidden, $this->deposit($unit, (string) $property->settings?->security_deposit_amount), (string) $bill->minus($paid)->toScale(2), $reservation->company_id !== null && $reservation->billing_mode !== BillingMode::EachPays, (string) $paid->toScale(2));
    }

    /**
     * The sum of the Scheduled tax rows of the unit's nights, by tax id.
     *
     * @param  list<array{rows: list<FolioRowData>}>  $blocks  The Scheduled blocks of the unit.
     * @param  list<int>  $taxIds
     * @return array<int, BigDecimal>
     */
    private function taxSums(array $blocks, array $taxIds): array
    {
        $sums = [];

        foreach ($blocks as $block) {
            foreach ($block['rows'] as $row) {
                $taxId = $this->checkInTaxRule->deskTaxId($row->key, $taxIds);

                if ($taxId !== null) {
                    $sums[$taxId] = ($sums[$taxId] ?? BigDecimal::zero())->plus($row->amount);
                }
            }
        }

        return $sums;
    }

    /**
     * The full bill of the unit: every Scheduled row (nights, inclusions, add-ons, taxes, less discounts). Discount rows
     * are already negative.
     *
     * @param  list<array{rows: list<FolioRowData>}>  $blocks  The Scheduled blocks of the unit.
     */
    private function billTotal(array $blocks): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($blocks as $block) {
            foreach ($block['rows'] as $row) {
                $total = $total->plus($row->amount);
            }
        }

        return $total;
    }

    /**
     * What the guest or the OTA already paid towards the unit's bill: the Completed payments on its Unit folio less their
     * refunds and voids. A security deposit is not a payment of the bill. A payment on a Master folio covers the whole
     * group and cannot be split by unit, so it is not counted.
     */
    private function paidOnUnitFolio(ReservationUnit $unit, ?Folio $master): BigDecimal
    {
        $folio = $master === null ? $this->folioRouter->unitFolio($unit) : null;

        if ($folio === null) {
            return BigDecimal::zero();
        }

        $paid = BigDecimal::zero();

        foreach (Payment::query()->where('folio_id', $folio->id)->where('status', PaymentStatus::Completed)->where('purpose', '!=', PaymentPurpose::SecurityDeposit)->get(['amount', 'refund_of_id']) as $payment) {
            $paid = $payment->refund_of_id === null ? $paid->plus($payment->amount) : $paid->minus($payment->amount);
        }

        return $paid;
    }

    /**
     * The default deposit, unless the unit already holds one (a deposit taken before check-in is not asked twice).
     */
    private function deposit(ReservationUnit $unit, string $amount): ?string
    {
        if ($amount === '' || BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            return null;
        }

        $held = Payment::query()
            ->whereHas('folio', fn ($folios) => $folios->where('reservation_unit_id', $unit->id)->where('type', FolioType::Unit))
            ->whereNull('refund_of_id')
            ->where('purpose', PaymentPurpose::SecurityDeposit)
            ->where('status', PaymentStatus::Completed)
            ->exists();

        return $held ? null : $amount;
    }
}
