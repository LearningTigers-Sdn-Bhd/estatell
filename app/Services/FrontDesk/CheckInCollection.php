<?php

namespace App\Services\FrontDesk;

use App\Data\Folio\FolioRowData;
use App\Data\Reservations\CheckInCollectionData;
use App\Enums\BillingMode;
use App\Enums\FolioType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SystemTransactionCode;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\ReservationUnit;
use App\Models\ReservationUnitTaxWaiver;
use App\Models\TransactionCode;
use App\Services\Catalogues\CheckInTaxRule;
use App\Services\Folio\CollectedTaxPayer;
use App\Services\Folio\FolioRouter;
use App\Services\Folio\ScheduledFolioRows;
use Brick\Math\BigDecimal;

/**
 * What Collect now asks the guest to pay at check-in, for one unit: the taxes flagged **Collect at check-in** and the
 * security deposit. A waived tax keeps its row with 0 and who paid it, and a tax staff can mark as paid elsewhere says so.
 * When the room charges go to the Master folio and the guest does not pay the collected taxes there, the tax rows
 * stay with `chargedTo` (the Master folio or the company) and add nothing to `taxesTotal`. On a Master no company pays,
 * they are `payableOnMaster`: the form may tick them, and Collect now records them as an Advance on the Master folio. It reads only. The tax amounts are the Scheduled tax rows of the unit's nights (what the night
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
        $allBlocks = $this->scheduledRows->forReservation($property, $reservation, []);
        $blocks = array_values(array_filter($allBlocks, fn (array $block): bool => $block['unit'] === $unit->id));

        if ($flagged->isNotEmpty()) {
            $sums = $this->taxSums($blocks, array_values(array_map(intval(...), $flagged->pluck('id')->all())));
            $waivers = $unit->loadMissing('taxWaivers')->taxWaivers->keyBy('tax_id');
            $chargedTo = $hidden ? $this->chargedTo($master) : null;
            // No company pays this Master, so the guest may pay its collected taxes at the desk, into the Master.
            $payableOnMaster = $hidden && $master?->loadMissing('payerCompany')->payerCompany === null;

            foreach ($flagged as $tax) {
                $amount = $sums[$tax->id] ?? BigDecimal::zero();
                $waiver = $waivers->get($tax->id);

                if ($waiver !== null) {
                    $taxes[] = ['taxId' => $tax->id, 'name' => $tax->name, 'amount' => '0.00', 'exempt' => false, 'waivedBy' => $this->payer($waiver), 'chargedTo' => null, 'payableOnMaster' => false, 'waivable' => false];

                    continue;
                }

                if ($amount->isZero() && ! $tax->foreign_guests_only) {
                    continue;
                }

                if (! $hidden) {
                    $total = $total->plus($amount);
                }

                $taxes[] = [
                    'taxId' => $tax->id,
                    'name' => $tax->name,
                    'amount' => (string) $amount->toScale(2),
                    'exempt' => $amount->isZero(),
                    'waivedBy' => null,
                    'chargedTo' => $amount->isZero() ? null : $chargedTo,
                    'payableOnMaster' => $payableOnMaster && ! $amount->isZero(),
                    'waivable' => $tax->can_be_waived && ! $amount->isZero(),
                ];
            }
        }

        $bill = $this->billTotal($blocks);
        $paid = BigDecimal::min($bill, BigDecimal::max(BigDecimal::zero(), $this->paidOnUnitFolio($unit, $master)));
        $unitFolio = $this->folioRouter->unitFolio($unit);
        $charges = $this->charges($blocks, $unitFolio?->id);
        $unitTotal = array_reduce(array_filter($charges, fn (array $charge): bool => $charge['folio'] === 'unit'), fn (BigDecimal $sum, array $charge): BigDecimal => $sum->plus($charge['amount']), BigDecimal::zero());
        $chargesPaid = BigDecimal::min($unitTotal, BigDecimal::max(BigDecimal::zero(), $unitFolio === null ? BigDecimal::zero() : $this->paidOn($unitFolio)));
        $masterPaid = $master === null ? BigDecimal::zero() : BigDecimal::max(BigDecimal::zero(), $this->paidOn($master));
        $masterOwed = $master === null ? null : $this->stillOwed($master, $allBlocks);

        return new CheckInCollectionData(
            $taxes,
            (string) $total->toScale(2),
            $hidden,
            $this->deposit($unit, (string) $property->settings?->security_deposit_amount),
            (string) $bill->minus($paid)->toScale(2),
            $reservation->company_id !== null && $reservation->billing_mode !== BillingMode::EachPays,
            (string) $paid->toScale(2),
            $charges,
            (string) $chargesPaid->toScale(2),
            $masterOwed === null ? null : (string) $masterOwed->toScale(2),
            (string) $masterPaid->toScale(2),
        );
    }

    /**
     * Where a tax the guest does not pay at check-in is charged: the company that pays the Master folio, or the
     * Master folio itself when no company does.
     */
    private function chargedTo(?Folio $master): string
    {
        $company = $master?->loadMissing('payerCompany')->payerCompany;

        return $company === null
            ? __('On the master folio')
            : __('Billed to :company', ['company' => $company->name]);
    }

    /**
     * Who paid a waived tax, as the tax row shows it: "Paid by Trip.com" or "Paid by guest".
     */
    private function payer(ReservationUnitTaxWaiver $waiver): string
    {
        return $waiver->reason->needsPaidTo()
            ? __('Paid by :payer', ['payer' => $waiver->paid_to])
            : __('Paid by guest');
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
     * The unit's Scheduled charges as a short bill: one **Room** entry (the room nights or the day use block with
     * their inclusions and taxes) and one entry per other transaction code (a speedboat fare with its landing fee),
     * wherever they are charged. `folio` says where: the unit's own folio (`unit`), the Master (`master`) or another
     * folio (`other`). Only the unit folio and a Master no company pays are `payable` at the desk; a company's folio
     * says `Billed to …`. The collected taxes the guest pays on the unit folio are tax rows of their own, so they are
     * left out, and `holdsTaxes` is false on a Room whose collected taxes were split off that way.
     *
     * @param  list<array{target: int|null, unit: int, date: string, code: TransactionCode, follows?: TransactionCode|null, rows: list<FolioRowData>, guestTax?: true}>  $blocks
     * @return list<array{codeId: int, name: string, count: int, perNight: bool, label: string, amount: string, isStay: bool, holdsTaxes: bool, folio: string, chargedTo: string|null, payable: bool}>
     */
    private function charges(array $blocks, ?int $unitFolioId): array
    {
        $stayKeys = [SystemTransactionCode::RoomRevenue->value, SystemTransactionCode::DayUseRevenue->value];
        $folios = Folio::query()->with('payerCompany')->whereIn('id', array_values(array_unique(array_filter(array_column($blocks, 'target')))))->get()->keyBy('id');
        $splitTaxes = array_any($blocks, fn (array $block): bool => $block['guestTax'] ?? false);
        $entries = [];

        foreach ($blocks as $block) {
            if ($block['guestTax'] ?? false) {
                continue;
            }

            $code = $block['code'];
            $isStay = in_array($code->system_key, $stayKeys, true) || in_array(($block['follows'] ?? null)?->system_key, $stayKeys, true);
            $key = $isStay ? 'stay' : 'code:'.$code->id;
            $entry = $entries[$key] ?? ['code' => $code, 'isStay' => $isStay, 'count' => 0, 'amount' => BigDecimal::zero(), 'target' => $block['target']];

            if ($isStay && in_array($code->system_key, $stayKeys, true)) {
                $entry['code'] = $code;
                $entry['count']++;
            } elseif (! $isStay) {
                $entry['count']++;
            }

            foreach ($block['rows'] as $row) {
                $entry['amount'] = $entry['amount']->plus($row->amount);
            }

            $entries[$key] = $entry;
        }

        uksort($entries, fn (string $a, string $b): int => ($a === 'stay' ? 0 : 1) <=> ($b === 'stay' ? 0 : 1));

        return array_values(array_filter(array_map(function (array $entry) use ($folios, $unitFolioId, $splitTaxes): array {
            $code = $entry['code'];
            $folio = $entry['target'] === null || $entry['target'] === $unitFolioId ? null : $folios->get($entry['target']);
            $place = match (true) {
                $folio === null => 'unit',
                $folio->type === FolioType::Master => 'master',
                default => 'other',
            };
            $company = $folio?->payerCompany;

            return [
                'codeId' => $code->id,
                'name' => $code->name,
                'count' => $entry['count'],
                'perNight' => $code->system_key === SystemTransactionCode::RoomRevenue->value,
                'label' => match (true) {
                    $code->system_key === SystemTransactionCode::RoomRevenue->value => trans_choice('Room · :count night|Room · :count nights', $entry['count']),
                    $code->system_key === SystemTransactionCode::DayUseRevenue->value => __('Day use'),
                    $entry['count'] > 1 => __(':name · :count×', ['name' => $code->name, 'count' => $entry['count']]),
                    default => $code->name,
                },
                'amount' => (string) $entry['amount']->toScale(2),
                'isStay' => $entry['isStay'],
                'holdsTaxes' => $entry['isStay'] && ! $splitTaxes,
                'folio' => $place,
                'chargedTo' => match (true) {
                    $place === 'unit' => null,
                    $company !== null => __('Billed to :company', ['company' => $company->name]),
                    $place === 'master' => __('On the master folio'),
                    default => __('On another folio'),
                },
                'payable' => $place === 'unit' || ($place === 'master' && $company === null),
            ];
        }, $entries), fn (array $charge): bool => BigDecimal::of($charge['amount'])->isPositive()));
    }

    /**
     * What a folio still owes: its posted lines (charges less payments) plus the Scheduled rows of every unit that go
     * to it. Never below 0.
     *
     * @param  list<array{target: int|null, rows: list<FolioRowData>}>  $blocks
     */
    private function stillOwed(Folio $folio, array $blocks): BigDecimal
    {
        $owed = BigDecimal::of((string) FolioLine::query()->where('folio_id', $folio->id)->sum('amount'));

        foreach ($blocks as $block) {
            if ($block['target'] === $folio->id) {
                foreach ($block['rows'] as $row) {
                    $owed = $owed->plus($row->amount);
                }
            }
        }

        return BigDecimal::max(BigDecimal::zero(), $owed);
    }

    /**
     * The Completed payments on a folio less their refunds and voids, without security deposits.
     */
    private function paidOn(Folio $folio): BigDecimal
    {
        $paid = BigDecimal::zero();

        foreach (Payment::query()->where('folio_id', $folio->id)->where('status', PaymentStatus::Completed)->where('purpose', '!=', PaymentPurpose::SecurityDeposit)->get(['amount', 'refund_of_id']) as $payment) {
            $paid = $payment->refund_of_id === null ? $paid->plus($payment->amount) : $paid->minus($payment->amount);
        }

        return $paid;
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
