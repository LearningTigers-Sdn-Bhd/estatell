<?php

namespace App\Actions\Pos;

use App\Actions\Folio\PostPosFolioCredit;
use App\Actions\Folio\ReverseLine;
use App\Data\Pos\PosRefundLineInputData;
use App\Data\Pos\PosRefundPayoutInputData;
use App\Enums\DocumentType;
use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use App\Enums\PosOrderStatus;
use App\Enums\PosOrderType;
use App\Enums\PosPaymentDirection;
use App\Enums\PosPaymentStatus;
use App\Enums\PosShiftStatus;
use App\Enums\PosStockMovementKind;
use App\Enums\PosTenderType;
use App\Features\Pos;
use App\Models\FolioLine;
use App\Models\PosItem;
use App\Models\PosOrder;
use App\Models\PosOrderFolioLink;
use App\Models\PosOrderLine;
use App\Models\PosOrderLineTax;
use App\Models\PosOrderPayment;
use App\Models\PosOutletItem;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\Tax;
use App\Models\User;
use App\Services\Catalogues\CalculatedTax;
use App\Services\NightAudit\NextDocumentNumber;
use App\Services\Pos\MalaysiaCashRounding;
use App\Services\Pos\OpenPosBusinessDate;
use App\Services\Pos\PosAmountAllocator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Give a settled sale back, by line and quantity. The refund is its own order (`Refund`, with `refund_for_order_id`), with
 * one refund line for each refunded line (`refund_of_order_line_id`), so two identical dishes at different discounts come
 * back at their own exact values. A line is refunded in parts: each part takes its share of the original net, service charge
 * and every tax, and the last part takes exactly what is left, so the total never drifts a sen. Refund orders store positive
 * amounts; the `Out` direction and the order type carry the sign.
 *
 * The money goes back through the original payments (`refund_of_payment_id`), never above what each one still has
 * (`exceeds_refundable_balance`); with no payouts given it is shared in proportion to what is left on each. Cash rounds as it
 * did on the way in. A room charge is credited back on its folio: a whole untouched charge is reversed once
 * (`ReverseLine::forPos`), and a part is credited with matching negative net and tax lines (`PostPosFolioCredit`); a closed
 * folio cannot take it (`folio_closed_credit_note_required`: a credit note is issued instead). Refunded meals can be given
 * back to the guest, and fired stock returned. It is recorded on the Open business date and an open shift, under the original
 * order's row lock, so two refunds of the last item never both pass. `$approvedBy` is a user with `pos.refund`, already checked.
 * The same `$clientUuid` returns the earlier refund. A refusal writes nothing. See docs/34-native-pos/04-tax-checkout-and-pms.md.
 */
class RefundPosOrder
{
    public function __construct(
        private OpenPosBusinessDate $openPosBusinessDate,
        private NextDocumentNumber $nextDocumentNumber,
        private PosAmountAllocator $allocator,
        private MalaysiaCashRounding $cashRounding,
        private ReverseLine $reverseLine,
        private PostPosFolioCredit $postPosFolioCredit,
        private UndoMealRedemption $undoMealRedemption,
        private BumpKitchenVersion $bumpKitchenVersion,
        private PostRecipeStock $postRecipeStock,
    ) {}

    /**
     * @param  list<PosRefundLineInputData>  $lines
     * @param  list<PosRefundPayoutInputData>|null  $payouts
     */
    public function __invoke(User $actor, Property $property, PosOrder $original, array $lines, string $reason, string $clientUuid, User $approvedBy, ?PosShift $shift = null, ?array $payouts = null): PosActionResult
    {
        try {
            return DB::transaction(fn (): PosActionResult => $this->refund($actor, $property, $original, $lines, trim($reason), $clientUuid, $approvedBy, $shift, $payouts));
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }

    /**
     * @param  list<PosRefundLineInputData>  $inputs
     * @param  list<PosRefundPayoutInputData>|null  $payouts
     */
    private function refund(User $actor, Property $property, PosOrder $original, array $inputs, string $reason, string $clientUuid, User $approvedBy, ?PosShift $shift, ?array $payouts): PosActionResult
    {
        if (! Feature::for($property)->active(Pos::class)) {
            return PosActionResult::refused('pos_disabled');
        }

        $existing = PosOrder::query()->where('property_id', $property->id)->where('client_uuid', $clientUuid)->first();

        if ($existing !== null) {
            return PosActionResult::replayed($existing);
        }

        $businessDate = $this->openPosBusinessDate->lock($property);

        if (is_string($businessDate)) {
            return PosActionResult::refused($businessDate);
        }

        $original = PosOrder::query()->where('property_id', $property->id)->whereKey($original->id)->lockForUpdate()->first();

        if ($original === null || $original->status !== PosOrderStatus::Settled || $original->order_type !== PosOrderType::Sale) {
            return PosActionResult::refused('order_not_refundable');
        }

        if ($reason === '') {
            return PosActionResult::refused('reason_required');
        }

        $shift = $this->shift($property, $original, $shift);

        if ($shift === null) {
            return PosActionResult::refused('shift_not_open');
        }

        $plan = $this->plan($original, $inputs);

        if (is_string($plan)) {
            return PosActionResult::refused($plan);
        }

        $refund = $this->createRefundOrder($actor, $original, $shift, $clientUuid, $businessDate->date, $plan);
        $refundLines = $this->createRefundLines($refund, $plan, $inputs);

        // The kitchen stops cooking what was given back.
        foreach ($plan as $row) {
            ($this->bumpKitchenVersion)($row['line']->kitchenStation);
        }
        $this->giveBackStockAndMeals($actor, $original, $plan, $refundLines, $inputs);
        $this->payOut($actor, $property, $original, $refund, $shift, $approvedBy, $payouts);

        activity()->performedOn($refund)->causedBy($actor)->event('pos.order_refunded')
            ->withProperties([
                'property_id' => $property->id,
                'refund_for_order_id' => $original->id,
                'order_number' => $refund->order_number,
                'grand_total' => $refund->grand_total,
                'reason' => $reason,
                'approved_by_user_id' => $approvedBy->id,
                'business_date' => $businessDate->date->toDateString(),
            ])
            ->log('POS order refunded');

        $refund->forceFill(['order_note' => $reason])->save();

        return PosActionResult::saved($refund->refresh());
    }

    private function shift(Property $property, PosOrder $original, ?PosShift $shift): ?PosShift
    {
        $query = PosShift::query()->where('property_id', $property->id)->where('pos_outlet_id', $original->pos_outlet_id)->where('status', PosShiftStatus::Open->value);

        return $shift === null
            ? $query->orderBy('id')->lockForUpdate()->first()
            : $query->whereKey($shift->id)->lockForUpdate()->first();
    }

    /**
     * Work out what each refunded line gives back. Returns a refusal code, or the plan.
     *
     * @param  list<PosRefundLineInputData>  $inputs
     * @return array<int, array{line: PosOrderLine, quantity: int, amounts: array<string, string>, taxes: list<array{row: PosOrderLineTax, base: string, amount: string}>}>|string
     */
    private function plan(PosOrder $original, array $inputs): array|string
    {
        if ($inputs === [] || count(array_unique(array_map(fn (PosRefundLineInputData $input): int => $input->orderLineId, $inputs))) !== count($inputs)) {
            return 'refund_lines_invalid';
        }

        $lines = $original->lines()->with('taxes')->lockForUpdate()->get()->keyBy('id');
        $plan = [];

        foreach ($inputs as $input) {
            $line = $lines->get($input->orderLineId);

            if ($line === null || $line->line_kind === PosOrderLineKind::PackagingFee || $line->kitchen_status === PosKitchenStatus::Voided) {
                return 'refund_lines_invalid';
            }

            $row = $this->planLine($line, $input->quantity);

            if (is_string($row)) {
                return $row;
            }

            $plan[$line->id] = $row;
        }

        $packaging = $lines->first(fn (PosOrderLine $line): bool => $line->line_kind === PosOrderLineKind::PackagingFee);

        if ($packaging !== null && $this->everyItemFullyRefunded($lines, $plan)) {
            $remaining = (int) $packaging->quantity - $this->refundedQuantity($packaging);

            $row = $remaining > 0 ? $this->planLine($packaging, $remaining) : null;

            if (is_array($row)) {
                $plan[$packaging->id] = $row;
            }
        }

        return $plan;
    }

    /**
     * @param  Collection<int, PosOrderLine>  $lines
     * @param  array<int, mixed>  $plan
     */
    private function everyItemFullyRefunded(Collection $lines, array $plan): bool
    {
        return $lines->every(fn (PosOrderLine $line): bool => $line->line_kind === PosOrderLineKind::PackagingFee
            || $line->kitchen_status === PosKitchenStatus::Voided
            || $this->units($line) - $this->refundedQuantity($line) - ($plan[$line->id]['quantity'] ?? 0) <= 0);
    }

    /**
     * Refundable units of a line: its count, or one for a line sold by weight, which is refunded whole.
     */
    private function units(PosOrderLine $line): int
    {
        return $line->variant?->item?->sold_by_weight === true ? 1 : (int) $line->quantity;
    }

    private function refundedQuantity(PosOrderLine $line): int
    {
        return (int) PosOrderLine::query()->where('refund_of_order_line_id', $line->id)->sum('quantity');
    }

    /**
     * @return array{line: PosOrderLine, quantity: int, amounts: array<string, string>, taxes: list<array{row: PosOrderLineTax, base: string, amount: string}>}|string
     */
    private function planLine(PosOrderLine $line, int $quantity): array|string
    {
        $total = $this->units($line);
        $before = $this->refundedQuantity($line);

        if ($quantity < 1 || $before + $quantity > $total) {
            return 'exceeds_refundable_balance';
        }

        $part = fn (string $amount): string => $this->share($amount, $before + $quantity, $total)->minus($this->share($amount, $before, $total))->toScale(2)->__toString();

        $taxes = [];
        $taxTotal = BigDecimal::zero();

        foreach ($line->taxes as $row) {
            $amount = $part($row->tax_amount);
            $taxTotal = $taxTotal->plus($amount);
            $taxes[] = ['row' => $row, 'base' => $part($row->taxable_base), 'amount' => $amount];
        }

        $net = $part($line->net_amount);
        $serviceCharge = $part($line->service_charge_amount);

        return [
            'line' => $line,
            'quantity' => $quantity,
            'amounts' => [
                'line_gross' => $part($line->line_gross),
                'discount_amount' => $part($line->discount_amount),
                'net_amount' => $net,
                'service_charge_amount' => $serviceCharge,
                'tax_amount' => $taxTotal->toScale(2)->__toString(),
                'line_total' => BigDecimal::of($net)->plus($serviceCharge)->plus($taxTotal)->toScale(2)->__toString(),
            ],
            'taxes' => $taxes,
        ];
    }

    private function share(string $amount, int $quantity, int $total): BigDecimal
    {
        return BigDecimal::of($amount)->multipliedBy($quantity)->dividedBy($total, 2, RoundingMode::HalfUp);
    }

    /**
     * @param  array<int, array{line: PosOrderLine, quantity: int, amounts: array<string, string>, taxes: list<array{row: PosOrderLineTax, base: string, amount: string}>}>  $plan
     */
    private function createRefundOrder(User $actor, PosOrder $original, PosShift $shift, string $clientUuid, CarbonImmutable $date, array $plan): PosOrder
    {
        $sum = fn (string $key): string => array_reduce($plan, fn (BigDecimal $total, array $row): BigDecimal => $total->plus($row['amounts'][$key]), BigDecimal::zero())->toScale(2)->__toString();
        $number = $this->nextDocumentNumber->__invoke($original->property, DocumentType::PosOrder);

        $refund = new PosOrder(['guest_count' => $original->guest_count]);
        $refund->property_id = $original->property_id;
        $refund->pos_outlet_id = $original->pos_outlet_id;
        $refund->pos_shift_id = $shift->id;
        $refund->business_date = $date;
        $refund->client_uuid = $clientUuid;
        $refund->order_number = $number->reference;
        $refund->order_type = PosOrderType::Refund;
        $refund->refund_for_order_id = $original->id;
        $refund->pos_dining_option_id = $original->pos_dining_option_id;
        $refund->reservation_unit_id = $original->reservation_unit_id;
        $refund->source = $original->source;
        $refund->status = PosOrderStatus::Settled;
        $refund->opened_by_user_id = $actor->id;
        $refund->closed_by_user_id = $actor->id;
        $refund->subtotal = $sum('net_amount');
        $refund->discount_total = $sum('discount_amount');
        $refund->service_charge_total = $sum('service_charge_amount');
        $refund->tax_total = $sum('tax_amount');
        $refund->grand_total = $sum('line_total');
        $refund->opened_at = now();
        $refund->settled_at = now();
        $refund->save();

        return $refund;
    }

    /**
     * @param  array<int, array{line: PosOrderLine, quantity: int, amounts: array<string, string>, taxes: list<array{row: PosOrderLineTax, base: string, amount: string}>}>  $plan
     * @param  list<PosRefundLineInputData>  $inputs
     * @return array<int, PosOrderLine>
     */
    private function createRefundLines(PosOrder $refund, array $plan, array $inputs): array
    {
        $created = [];

        foreach ($plan as $row) {
            $original = $row['line'];
            $line = new PosOrderLine;
            $line->property_id = $refund->property_id;
            $line->pos_order_id = $refund->id;
            $line->pos_item_variant_id = $original->pos_item_variant_id;
            $line->line_kind = $original->line_kind;
            $line->price_includes_tax = $original->price_includes_tax;
            $line->service_charge_exempt = $original->service_charge_exempt;
            $line->refund_of_order_line_id = $original->id;
            $line->transaction_code_id = $original->transaction_code_id;
            $line->is_entitlement_covered = $original->is_entitlement_covered;
            $line->item_name = $original->item_name;
            $line->variant_name = $original->variant_name;
            $line->internal_name = $original->internal_name;
            $line->quantity = $row['quantity'].'.000';
            $line->unit_price = $original->unit_price;
            $line->unit_cost = $original->unit_cost;
            $line->discount_type = $original->discount_type;
            $line->discount_value = $original->discount_value;
            $line->exclude_addons_from_discount = $original->exclude_addons_from_discount;
            $line->kitchen_status = PosKitchenStatus::Served;
            $line->fired_at = now();
            $line->forceFill($row['amounts']);
            $line->save();

            $original->loadMissing('modifiers');
            foreach ($original->modifiers as $modifier) {
                $modifier->replicate()->forceFill(['pos_order_line_id' => $line->id])->save();
            }

            foreach ($row['taxes'] as $tax) {
                $taxRow = new PosOrderLineTax;
                $taxRow->pos_order_line_id = $line->id;
                $taxRow->tax_id = $tax['row']->tax_id;
                $taxRow->transaction_code_id = $tax['row']->transaction_code_id;
                $taxRow->tax_code = $tax['row']->tax_code;
                $taxRow->tax_name = $tax['row']->tax_name;
                $taxRow->lhdn_tax_type = $tax['row']->lhdn_tax_type;
                $taxRow->rate_type = $tax['row']->rate_type;
                $taxRow->tax_rate = $tax['row']->tax_rate;
                $taxRow->is_inclusive = $tax['row']->is_inclusive;
                $taxRow->taxable_base = $tax['base'];
                $taxRow->tax_amount = $tax['amount'];
                $taxRow->save();
            }

            $created[$original->id] = $line;
        }

        return $created;
    }

    /**
     * @param  array<int, array{line: PosOrderLine, quantity: int, amounts: array<string, string>, taxes: list<array{row: PosOrderLineTax, base: string, amount: string}>}>  $plan
     * @param  array<int, PosOrderLine>  $refundLines
     * @param  list<PosRefundLineInputData>  $inputs
     */
    private function giveBackStockAndMeals(User $actor, PosOrder $original, array $plan, array $refundLines, array $inputs): void
    {
        foreach ($inputs as $input) {
            $row = $plan[$input->orderLineId];
            $line = $row['line'];

            if ($input->returnStock && $line->pos_item_variant_id !== null && $line->kitchen_status->isLocked()) {
                PosOutletItem::query()->where('pos_outlet_id', $original->pos_outlet_id)->where('pos_item_variant_id', $line->pos_item_variant_id)->whereNotNull('stock_countdown')->increment('stock_countdown', $row['quantity']);
                PosItem::query()->whereKey($line->variant->pos_item_id)->whereNotNull('shared_stock_count')->increment('shared_stock_count', $row['quantity']);
                ($this->postRecipeStock)($refundLines[$line->id], PosStockMovementKind::RefundReturn);
            }

            if ($input->restoreMealEntitlement && $line->meal_redemption_id !== null) {
                $result = $this->undoMealRedemption->forPos($actor, $line->mealRedemption);

                if ($result->isRefused() && $result->refusal !== 'already_undone') {
                    throw new PosRefusedException('meal_restore_not_possible');
                }
            }
        }
    }

    /**
     * @param  list<PosRefundPayoutInputData>|null  $requested
     */
    private function payOut(User $actor, Property $property, PosOrder $original, PosOrder $refund, PosShift $shift, User $approvedBy, ?array $requested): void
    {
        $payments = $original->payments()->where('direction', PosPaymentDirection::In->value)->where('status', PosPaymentStatus::Captured->value)->lockForUpdate()->orderBy('id')->get();
        $remaining = $payments->mapWithKeys(fn (PosOrderPayment $payment): array => [$payment->id => BigDecimal::of($payment->amount)->minus((string) $this->refundedAmount($payment))]);
        $total = BigDecimal::of($refund->grand_total);

        $amounts = $requested === null
            ? $this->autoPayouts($payments, $remaining, $total)
            : $this->requestedPayouts($payments, $remaining, $requested, $total);

        $cashTotal = $payments->filter(fn (PosOrderPayment $payment): bool => $payment->tender_type === PosTenderType::Cash)->reduce(fn (BigDecimal $sum, PosOrderPayment $payment): BigDecimal => $sum->plus($amounts[$payment->id] ?? '0'), BigDecimal::zero());
        $adjustment = $this->cashRounding->adjustment($original->outlet->rounding_mode, $original->outlet->rounding_policy, $total->toScale(2)->__toString(), $cashTotal->toScale(2)->__toString());
        $adjusted = false;
        $folioPaid = BigDecimal::zero();
        $index = 0;

        foreach ($payments as $payment) {
            $amount = BigDecimal::of($amounts[$payment->id] ?? '0');

            if (! $amount->isPositive()) {
                continue;
            }

            $payout = new PosOrderPayment;
            $payout->property_id = $property->id;
            $payout->pos_order_id = $refund->id;
            $payout->pos_shift_id = $shift->id;
            $payout->idempotency_key = 'refund:'.$refund->id.':'.$index++;
            $payout->refund_of_payment_id = $payment->id;
            $payout->payment_method_id = $payment->payment_method_id;
            $payout->direction = PosPaymentDirection::Out;
            $payout->tender_type = $payment->tender_type;
            $payout->reconciliation_status = $payment->reconciliation_status;
            $payout->amount = $amount->toScale(2)->__toString();
            $payout->rounding_adjustment = '0.00';
            $payout->reservation_unit_id = $payment->reservation_unit_id;
            $payout->folio_id = $payment->folio_id;
            $payout->reference = $payment->reference;
            $payout->status = PosPaymentStatus::Captured;
            $payout->acted_by_user_id = $actor->id;
            $payout->approved_by_user_id = $approvedBy->id;
            $payout->paid_at = now();

            if ($payment->tender_type === PosTenderType::Cash && ! $adjusted) {
                $payout->rounding_adjustment = $adjustment;
                $adjusted = true;
            }

            $payout->save();

            if ($payment->tender_type === PosTenderType::RoomCharge) {
                $this->creditFolio($actor, $original, $refund, $payment, $payout);
                $folioPaid = $folioPaid->plus($amount);
            }
        }

        $refund->forceFill([
            'rounding_adjustment' => $adjustment,
            'folio_posted_amount' => $folioPaid->toScale(2)->__toString(),
            'settled_on_folio' => $folioPaid->isEqualTo($total),
        ])->save();
    }

    private function refundedAmount(PosOrderPayment $payment): string
    {
        return (string) PosOrderPayment::query()
            ->where('refund_of_payment_id', $payment->id)
            ->where('direction', PosPaymentDirection::Out->value)
            ->where('status', PosPaymentStatus::Captured->value)
            ->sum('amount');
    }

    /**
     * Share the refund over the original payments in proportion to what each still has.
     *
     * @param  Collection<int, PosOrderPayment>  $payments
     * @param  Collection<int, BigDecimal>  $remaining
     * @return array<int, string>
     */
    private function autoPayouts(Collection $payments, Collection $remaining, BigDecimal $total): array
    {
        if ($total->isGreaterThan($remaining->reduce(fn (BigDecimal $sum, BigDecimal $left): BigDecimal => $sum->plus($left), BigDecimal::zero()))) {
            throw new PosRefusedException('exceeds_refundable_balance');
        }

        $ids = $payments->pluck('id')->all();
        $shares = $this->allocator->allocate($total->toScale(2)->__toString(), array_values(array_map(fn (int $id): string => $remaining[$id]->toScale(2)->__toString(), $ids)));

        return array_combine($ids, $shares);
    }

    /**
     * @param  Collection<int, PosOrderPayment>  $payments
     * @param  Collection<int, BigDecimal>  $remaining
     * @param  list<PosRefundPayoutInputData>  $requested
     * @return array<int, string>
     */
    private function requestedPayouts(Collection $payments, Collection $remaining, array $requested, BigDecimal $total): array
    {
        $amounts = [];
        $sum = BigDecimal::zero();

        foreach ($requested as $payout) {
            if (! $payments->contains('id', $payout->paymentId) || isset($amounts[$payout->paymentId]) || preg_match('/^\d{1,10}(\.\d{1,2})?$/', $payout->amount) !== 1) {
                throw new PosRefusedException('refund_payout_invalid');
            }

            if (BigDecimal::of($payout->amount)->isGreaterThan($remaining[$payout->paymentId])) {
                throw new PosRefusedException('exceeds_refundable_balance');
            }

            $amounts[$payout->paymentId] = BigDecimal::of($payout->amount)->toScale(2)->__toString();
            $sum = $sum->plus($payout->amount);
        }

        if (! $sum->isEqualTo($total)) {
            throw new PosRefusedException('refund_payout_mismatch');
        }

        return $amounts;
    }

    /**
     * Credit a room charge refund back on the folio: reverse a whole untouched charge once, or credit the part with negative
     * net and tax lines, and keep each link's refunded net and tax so the last part credits exactly what is left.
     */
    private function creditFolio(User $actor, PosOrder $original, PosOrder $refund, PosOrderPayment $payment, PosOrderPayment $payout): void
    {
        $links = PosOrderFolioLink::query()->where('pos_order_payment_id', $payment->id)->lockForUpdate()->orderBy('id')->get();
        $untouched = $links->every(fn (PosOrderFolioLink $link): bool => BigDecimal::of($link->refunded_net)->isZero() && BigDecimal::of($link->refunded_tax)->isZero());

        if ($untouched && BigDecimal::of($payout->amount)->isEqualTo($payment->amount)) {
            foreach ($links as $link) {
                $result = $this->reverseLine->forPos($actor, $link->folioLine);

                if ($result->isRefused()) {
                    throw new PosRefusedException('folio_closed_credit_note_required');
                }

                $link->forceFill(['refunded_net' => $link->net_amount, 'refunded_tax' => $link->tax_amount])->save();
            }

            return;
        }

        $remainders = array_values($links->map(fn (PosOrderFolioLink $link): string => BigDecimal::of($link->net_amount)->minus($link->refunded_net)->plus($link->tax_amount)->minus($link->refunded_tax)->toScale(2)->__toString())->all());
        $shares = $this->allocator->allocate($payout->amount, $remainders);
        $currency = $original->property->settings->currency->getCurrencyCode();

        foreach ($links->values() as $position => $link) {
            if (! BigDecimal::of($shares[$position])->isPositive()) {
                continue;
            }

            $netLeft = BigDecimal::of($link->net_amount)->minus($link->refunded_net)->toScale(2)->__toString();
            $taxLeft = BigDecimal::of($link->tax_amount)->minus($link->refunded_tax)->toScale(2)->__toString();
            [$net, $tax] = $this->allocator->allocate($shares[$position], [$netLeft, $taxLeft]);

            $taxLines = FolioLine::query()->where('parent_line_id', $link->folio_line_id)->whereNull('reversal_of_id')->whereDoesntHave('reversal')->orderBy('id')->get();
            $taxShares = $this->allocator->allocate($tax, array_values($taxLines->map(fn (FolioLine $line): string => BigDecimal::of($line->amount)->abs()->toScale(2)->__toString())->all()));
            $taxes = [];

            foreach ($taxLines->values() as $taxPosition => $taxLine) {
                $row = Tax::query()->where('property_id', $original->property_id)->where('transaction_code_id', $taxLine->transaction_code_id)->first();

                if ($row !== null && BigDecimal::of($taxShares[$taxPosition])->isPositive()) {
                    $taxes[] = new CalculatedTax($row->id, $row->transaction_code_id, $taxLine->description, Money::of($taxShares[$taxPosition], $currency), false);
                }
            }

            $result = ($this->postPosFolioCredit)(
                $actor,
                $link->folioLine,
                $original->outlet->name.' — Refund #'.$refund->order_number,
                Money::of($net, $currency),
                $taxes,
                'native-pos-refund:'.$refund->id.':link:'.$link->id,
                $refund,
            );

            if ($result->isRefused()) {
                throw new PosRefusedException('folio_closed_credit_note_required');
            }

            $link->forceFill([
                'refunded_net' => BigDecimal::of($link->refunded_net)->plus($net)->toScale(2)->__toString(),
                'refunded_tax' => BigDecimal::of($link->refunded_tax)->plus($tax)->toScale(2)->__toString(),
            ])->save();
        }
    }
}
