<?php

namespace App\Actions\Pos;

use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostPrecalculatedCharge;
use App\Data\Pos\PosTenderInputData;
use App\Enums\DocumentType;
use App\Enums\PosOrderStatus;
use App\Enums\PosPaymentDirection;
use App\Enums\PosPaymentStatus;
use App\Enums\PosReconciliationStatus;
use App\Enums\PosShiftStatus;
use App\Enums\PosTenderType;
use App\Enums\UnitStatus;
use App\Features\NativePos;
use App\Models\Folio;
use App\Models\PosOrder;
use App\Models\PosOrderFolioLink;
use App\Models\PosOrderLine;
use App\Models\PosOrderPayment;
use App\Models\PosOutletPaymentMethod;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Catalogues\CalculatedTax;
use App\Services\Folio\FolioRouter;
use App\Services\NightAudit\NextDocumentNumber;
use App\Services\Pos\MalaysiaCashRounding;
use App\Services\Pos\OpenPosBusinessDate;
use App\Services\Pos\PosAmountAllocator;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

/**
 * Take payment for an open order and close it, in one transaction. Locks are taken in the global order: properties (shared)
 * and the Open business date, the rooms in id order, then the order and its shift. The order is priced one last time
 * (`sst_registration_missing` and the other calculator refusals stop it), its Draft lines are fired, and the tenders must
 * add up to the bill exactly: `insufficient_payment` below it, `overpayment_not_allowed` above it. Cash handed over
 * is kept apart from the amount applied, so RM 100 for a RM 40 bill is RM 40 and RM 60 change. Cash is rounded to 5 sen
 * under the outlet's policy and the adjustment is stored on the first cash row.
 *
 * A room charge posts only its own part of the bill to the guest's folio, never a payment: every net, service charge and tax
 * bucket of the order is shared out in whole sen in proportion to the tender, and posted through `PostPrecalculatedCharge`
 * on the folio each line's code routes to. The stay must be in house and not blocked (`room_no_post`, `folio_not_postable`),
 * within the outlet's cumulative limit unless a manager approved it (`room_charge_limit_exceeded`), and signed when the
 * outlet asks (`signature_required`). Covered meal lines are redeemed one guest at a time, after the guest confirmed when the
 * outlet asks (`confirmation_required`). Cash, card and the like stay in the shift and the daily journal, never on the folio.
 * The order number comes from the `pos_order` sequence once everything has passed, and the order is stamped with the Open
 * business date, so an order opened at 1:55 AM settles cleanly after the audit. Settling a settled order returns it.
 * A refusal writes nothing. See docs/34-native-pos/04-tax-checkout-and-pms.md.
 */
class SettlePosOrder
{
    public function __construct(
        private OpenPosBusinessDate $openPosBusinessDate,
        private RecalculatePosOrder $recalculate,
        private FireOrderLines $fireOrderLines,
        private RevokeTableQrSessions $revokeTableQrSessions,
        private MalaysiaCashRounding $cashRounding,
        private PosAmountAllocator $allocator,
        private NextDocumentNumber $nextDocumentNumber,
        private FolioRouter $folioRouter,
        private CreateFolio $createFolio,
        private PostPrecalculatedCharge $postPrecalculatedCharge,
        private RedeemMeal $redeemMeal,
    ) {}

    /**
     * @param  list<PosTenderInputData>  $tenders
     */
    public function __invoke(User $actor, Property $property, PosOrder $order, array $tenders, bool $guestSigned = false, bool $mealConfirmed = false, ?User $limitApprover = null): PosActionResult
    {
        try {
            return DB::transaction(fn (): PosActionResult => $this->settle($actor, $property, $order, $tenders, $guestSigned, $mealConfirmed, $limitApprover));
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }

    /**
     * @param  list<PosTenderInputData>  $tenders
     */
    private function settle(User $actor, Property $property, PosOrder $order, array $tenders, bool $guestSigned, bool $mealConfirmed, ?User $limitApprover): PosActionResult
    {
        if (! Feature::for($property)->active(NativePos::class)) {
            return PosActionResult::refused('native_pos_disabled');
        }

        $businessDate = $this->openPosBusinessDate->lock($property);

        if (is_string($businessDate)) {
            return PosActionResult::refused($businessDate);
        }

        $unitIds = collect($tenders)->where('tenderType', PosTenderType::RoomCharge)->pluck('reservationUnitId')->filter()->unique()->sort()->values()->all();
        $units = ReservationUnit::query()->where('property_id', $property->id)->whereIn('id', $unitIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

        if ($order === null) {
            return PosActionResult::refused('order_invalid');
        }

        if ($order->status === PosOrderStatus::Settled) {
            return PosActionResult::replayed($order);
        }

        $shift = $order->pos_shift_id === null ? null : PosShift::query()->whereKey($order->pos_shift_id)->lockForUpdate()->first();

        if ($order->status !== PosOrderStatus::Open || ! $order->lines()->where('kitchen_status', '!=', 'voided')->exists()) {
            return PosActionResult::refused('order_not_settleable');
        }

        if ($shift === null || $shift->status !== PosShiftStatus::Open || $shift->pos_outlet_id !== $order->pos_outlet_id) {
            return PosActionResult::refused('shift_not_open');
        }

        $priced = ($this->recalculate)($order);

        if ($priced->isRefused()) {
            return PosActionResult::refused((string) $priced->refusal);
        }

        ($this->fireOrderLines)($actor, $property, $order);
        $order->refresh();

        $outlet = $order->outlet;
        $grandTotal = BigDecimal::of($order->grand_total);
        $lines = $order->lines()->where('kitchen_status', '!=', 'voided')->with('taxes')->orderBy('id')->get();

        $this->checkTenderTotal($tenders, $grandTotal);
        $payments = $this->buildPayments($actor, $order, $shift, $outlet->id, $tenders, $units, $grandTotal);

        $this->redeemCoveredMeals($actor, $order, $lines, $mealConfirmed, $businessDate->date);

        $number = $this->nextDocumentNumber->__invoke($property, DocumentType::PosOrder);
        $folioPosted = BigDecimal::zero();

        foreach ($payments as $row) {
            $payment = $row['payment'];
            $payment->pos_order_id = $order->id;
            $payment->save();

            if ($payment->tender_type === PosTenderType::RoomCharge) {
                $this->checkRoomCharge($property, $order, $payment, $row['unit'], $guestSigned, $limitApprover);
                $this->postToFolio($actor, $property, $order, $lines, $payment, $row['unit'], $number->reference);
                $folioPosted = $folioPosted->plus($payment->amount);
            }
        }

        $order->forceFill([
            'status' => PosOrderStatus::Settled,
            'order_number' => $number->reference,
            'business_date' => $businessDate->date,
            'settled_at' => now(),
            'closed_by_user_id' => $actor->id,
            'rounding_adjustment' => array_reduce($payments, fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['payment']->rounding_adjustment), BigDecimal::zero())->toScale(2)->__toString(),
            'folio_posted_amount' => $folioPosted->toScale(2)->__toString(),
            'settled_on_folio' => $folioPosted->isEqualTo($grandTotal),
            'receipt_claim_token' => Str::random(32),
        ])->save();

        if ($order->pos_table_id !== null) {
            ($this->revokeTableQrSessions)($property->id, $order->pos_table_id);
        }

        activity()->performedOn($order)->causedBy($actor)->event('pos.order_settled')
            ->withProperties([
                'property_id' => $property->id,
                'order_number' => $order->order_number,
                'grand_total' => $order->grand_total,
                'folio_posted_amount' => $order->folio_posted_amount,
                'tenders' => array_map(fn (array $row): array => ['type' => $row['payment']->tender_type->value, 'amount' => $row['payment']->amount], $payments),
                'limit_approved_by_user_id' => $limitApprover?->id,
            ])
            ->log('POS order settled');

        return PosActionResult::saved($order->refresh());
    }

    /**
     * @param  list<PosTenderInputData>  $tenders
     */
    private function checkTenderTotal(array $tenders, BigDecimal $grandTotal): void
    {
        $total = BigDecimal::zero();

        foreach ($tenders as $tender) {
            if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $tender->amount) !== 1 || ! BigDecimal::of($tender->amount)->isPositive()) {
                throw new PosRefusedException('tender_invalid');
            }

            $total = $total->plus($tender->amount);
        }

        if ($total->isLessThan($grandTotal)) {
            throw new PosRefusedException('insufficient_payment');
        }

        if ($total->isGreaterThan($grandTotal)) {
            throw new PosRefusedException('overpayment_not_allowed');
        }
    }

    /**
     * @param  list<PosTenderInputData>  $tenders
     * @param  Collection<int, ReservationUnit>  $units
     * @return list<array{payment: PosOrderPayment, unit: ReservationUnit|null}>
     */
    private function buildPayments(User $actor, PosOrder $order, PosShift $shift, int $outletId, array $tenders, $units, BigDecimal $grandTotal): array
    {
        $outlet = $order->outlet;
        $cashTotal = collect($tenders)->where('tenderType', PosTenderType::Cash)->reduce(fn (BigDecimal $sum, PosTenderInputData $tender): BigDecimal => $sum->plus($tender->amount), BigDecimal::zero());
        $adjustment = $this->cashRounding->adjustment($outlet->rounding_mode, $outlet->rounding_policy, $grandTotal->__toString(), $cashTotal->toScale(2)->__toString());
        $rows = [];
        $adjusted = false;

        foreach ($tenders as $index => $tender) {
            $payment = new PosOrderPayment;
            $payment->property_id = $order->property_id;
            $payment->pos_shift_id = $shift->id;
            $payment->idempotency_key = 'settle:'.$order->id.':'.$index;
            $payment->direction = PosPaymentDirection::In;
            $payment->tender_type = $tender->tenderType;
            $payment->reconciliation_status = PosReconciliationStatus::Verified;
            $payment->amount = BigDecimal::of($tender->amount)->toScale(2)->__toString();
            $payment->rounding_adjustment = '0.00';
            $payment->status = PosPaymentStatus::Captured;
            $payment->acted_by_user_id = $actor->id;
            $payment->reference = $tender->reference;
            $payment->paid_at = now();
            $unit = null;

            if ($tender->tenderType === PosTenderType::RoomCharge) {
                $unit = $units->get($tender->reservationUnitId);

                if ($unit === null) {
                    throw new PosRefusedException('unit_not_eligible');
                }

                $payment->reservation_unit_id = $unit->id;
            } else {
                $payment->payment_method_id = $this->paymentMethodFor($outletId, $tender);

                if ($tender->tenderType === PosTenderType::Cash) {
                    $due = BigDecimal::of($payment->amount);

                    if (! $adjusted) {
                        $payment->rounding_adjustment = $adjustment;
                        $due = $due->plus($adjustment);
                        $adjusted = true;
                    }

                    $tendered = $tender->tenderedAmount === null ? $due : BigDecimal::of($tender->tenderedAmount);

                    if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $tendered->__toString()) !== 1 || $tendered->isLessThan($due)) {
                        throw new PosRefusedException('tendered_cash_insufficient');
                    }

                    $payment->tendered_amount = $tendered->toScale(2)->__toString();
                    $payment->change_amount = $tendered->minus($due)->toScale(2)->__toString();
                }
            }

            $rows[] = ['payment' => $payment, 'unit' => $unit];
        }

        return $rows;
    }

    private function paymentMethodFor(int $outletId, PosTenderInputData $tender): int
    {
        $method = PosOutletPaymentMethod::query()
            ->where('pos_outlet_id', $outletId)
            ->where('active', true)
            ->where('tender_type', $tender->tenderType->value)
            ->when($tender->paymentMethodId !== null, fn ($query) => $query->where('payment_method_id', $tender->paymentMethodId))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        return $method->payment_method_id ?? throw new PosRefusedException('payment_method_invalid');
    }

    /**
     * @param  Collection<int, PosOrderLine>  $lines
     */
    private function redeemCoveredMeals(User $actor, PosOrder $order, Collection $lines, bool $mealConfirmed, CarbonInterface $serviceDate): void
    {
        $covered = $lines->where('is_entitlement_covered', true);

        if ($covered->isEmpty()) {
            return;
        }

        if ($order->outlet->require_meal_redemption_confirmation && ! $mealConfirmed) {
            throw new PosRefusedException('confirmation_required');
        }

        $unit = $order->reservationUnit;

        foreach ($covered as $line) {
            $period = $line->variant->item->mealPeriod;

            if ($unit === null || $period === null) {
                throw new PosRefusedException('not_entitled');
            }

            $result = ($this->redeemMeal)($actor, $unit, $period, CarbonImmutable::parse($serviceDate->toDateString()), 1, 'POS order '.$order->id);

            if ($result->isRefused()) {
                throw new PosRefusedException((string) $result->refusal);
            }

            $line->forceFill(['meal_redemption_id' => $result->redemption->id])->save();
        }
    }

    private function checkRoomCharge(Property $property, PosOrder $order, PosOrderPayment $payment, ?ReservationUnit $unit, bool $guestSigned, ?User $limitApprover): void
    {
        $outlet = $order->outlet;

        if ($unit === null || ! in_array($unit->status, [UnitStatus::CheckedIn, UnitStatus::CheckedOut], true)) {
            throw new PosRefusedException('folio_not_postable');
        }

        if ($unit->no_post) {
            throw new PosRefusedException('room_no_post');
        }

        if ($outlet->require_room_charge_signature && ! $guestSigned) {
            throw new PosRefusedException('signature_required');
        }

        if ($outlet->room_charge_limit !== null && $limitApprover === null) {
            $charged = BigDecimal::of((string) PosOrderPayment::query()
                ->where('property_id', $property->id)
                ->where('reservation_unit_id', $unit->id)
                ->where('tender_type', PosTenderType::RoomCharge->value)
                ->where('status', PosPaymentStatus::Captured->value)
                ->whereKeyNot($payment->id)
                ->selectRaw("coalesce(sum(case when direction = 'in' then amount else -amount end), 0) as total")
                ->value('total'));

            if ($charged->plus($payment->amount)->isGreaterThan($outlet->room_charge_limit)) {
                throw new PosRefusedException('room_charge_limit_exceeded');
            }
        }
    }

    /**
     * Post this room tender's share of every bucket of the order to the folios its lines route to, and link each root charge.
     *
     * @param  Collection<int, PosOrderLine>  $lines
     */
    private function postToFolio(User $actor, Property $property, PosOrder $order, $lines, PosOrderPayment $payment, ReservationUnit $unit, string $orderNumber): void
    {
        $outlet = $order->outlet;
        $currency = $property->settings->currency->getCurrencyCode();
        $folios = [];
        $folioFor = function (int $codeId) use (&$folios, $unit): Folio {
            if (! isset($folios[$codeId])) {
                $code = TransactionCode::query()->findOrFail($codeId);
                $folios[$codeId] = $this->folioRouter->targetFor($unit, $code) ?? ($this->createFolio)($unit->reservation, $unit);
            }

            return $folios[$codeId];
        };

        $itemLines = $lines->where('line_kind', '!=', 'packaging_fee');
        $primaryFolio = $folioFor($itemLines->first()->transaction_code_id ?? $lines->first()->transaction_code_id);
        $components = [];

        foreach ($lines as $line) {
            $folio = $line->line_kind->value === 'packaging_fee' ? $primaryFolio : $folioFor($line->transaction_code_id);
            $components[] = ['folio' => $folio, 'kind' => 'net', 'code' => $line->transaction_code_id, 'amount' => $line->net_amount];

            if (BigDecimal::of($line->service_charge_amount)->isPositive()) {
                $components[] = ['folio' => $folio, 'kind' => 'sc', 'code' => $outlet->service_charge_transaction_code_id, 'amount' => $line->service_charge_amount];
            }

            foreach ($line->taxes as $tax) {
                $components[] = ['folio' => $folio, 'kind' => 'tax', 'code' => $line->transaction_code_id, 'amount' => $tax->tax_amount, 'tax' => $tax];
            }
        }

        $shares = $this->allocator->allocate($payment->amount, array_map(fn (array $component): string => $component['amount'], $components));
        $groups = [];

        foreach ($components as $index => $component) {
            if (! BigDecimal::of($shares[$index])->isPositive()) {
                continue;
            }

            $key = $component['folio']->id.':'.($component['kind'] === 'sc' ? 'sc' : $component['code']);
            $groups[$key] ??= ['folio' => $component['folio'], 'code' => $component['code'], 'net' => BigDecimal::zero(), 'taxes' => []];

            if ($component['kind'] === 'tax') {
                $tax = $component['tax'];
                $groups[$key]['taxes'][$tax->tax_id] = [
                    'row' => $tax,
                    'amount' => ($groups[$key]['taxes'][$tax->tax_id]['amount'] ?? BigDecimal::zero())->plus($shares[$index]),
                ];
            } else {
                $groups[$key]['net'] = $groups[$key]['net']->plus($shares[$index]);
            }
        }

        foreach ($groups as $key => $group) {
            $code = TransactionCode::query()->findOrFail($group['code']);
            $taxes = array_values(array_map(
                fn (array $tax): CalculatedTax => new CalculatedTax($tax['row']->tax_id, $tax['row']->transaction_code_id, $tax['row']->tax_name, Money::of($tax['amount']->__toString(), $currency), $tax['row']->is_inclusive),
                $group['taxes'],
            ));

            $result = ($this->postPrecalculatedCharge)(
                $actor,
                $unit,
                $group['folio'],
                $code,
                $order->outlet->name.' — Order #'.$orderNumber,
                Money::of($group['net']->toScale(2)->__toString(), $currency),
                $taxes,
                'native-pos:'.$order->id.':pay:'.$payment->id.':f:'.$key,
                $order,
            );

            if ($result->isRefused()) {
                throw new PosRefusedException(in_array($result->refusal, ['room_no_post'], true) ? 'room_no_post' : 'folio_not_postable');
            }

            $root = $result->line() ?? throw new PosRefusedException('folio_not_postable');
            $net = BigDecimal::of($root->amount);
            $tax = array_reduce($taxes, fn (BigDecimal $sum, CalculatedTax $item): BigDecimal => $sum->plus($item->amount->getAmount()), BigDecimal::zero());

            $link = new PosOrderFolioLink;
            $link->property_id = $property->id;
            $link->pos_order_id = $order->id;
            $link->pos_order_payment_id = $payment->id;
            $link->folio_id = $root->folio_id;
            $link->folio_line_id = $root->id;
            $link->transaction_code_id = $code->id;
            $link->net_amount = $net->toScale(2)->__toString();
            $link->tax_amount = $tax->toScale(2)->__toString();
            $link->gross_amount = $net->plus($tax)->toScale(2)->__toString();
            $link->save();
        }

        $payment->forceFill(['folio_id' => $primaryFolio->id])->save();
    }
}
