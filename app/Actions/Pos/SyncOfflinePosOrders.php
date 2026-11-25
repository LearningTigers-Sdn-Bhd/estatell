<?php

namespace App\Actions\Pos;

use App\Data\Pos\PosOrderLineInputData;
use App\Data\Pos\PosTenderInputData;
use App\Enums\PosPricingType;
use App\Enums\PosReconciliationStatus;
use App\Enums\PosShiftStatus;
use App\Enums\PosTenderType;
use App\Models\PosDiningOption;
use App\Models\PosItemVariant;
use App\Models\PosMenuSnapshot;
use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use App\Models\PosOutlet;
use App\Models\PosOutletItem;
use App\Models\PosOutletPaymentMethod;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Replay the walk-in sales a register made while offline. Each order is priced from the menu snapshot it was sold from, so
 * a price change since does not move the guest's bill; a difference is logged as `pos.offline_price_drift`. Only cash and
 * static DuitNow are taken offline, and a DuitNow sale waits for bank-slip reconciliation. A sale whose shift has closed
 * joins the open shift and is marked `synced_late`. An order already synced is replayed, not written twice. A snapshot older
 * than seven days is refused (`snapshot_expired`). Each order commits or fails on its own. The official receipt number is
 * given here, by settlement. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class SyncOfflinePosOrders
{
    public function __construct(private CreatePosOrder $createPosOrder, private AddOrderLine $addOrderLine, private SettlePosOrder $settlePosOrder) {}

    /**
     * @param  list<array{client_uuid: string, snapshot_version: string, shift_id: ?int, lines: list<array{variant_id: int, quantity: int}>, tenders: list<array{tender_type: string, amount: ?string, tendered_amount: ?string}>}>  $orders
     * @return array<string, string> Each client uuid mapped to `synced`, `replayed` or a refusal code.
     */
    public function __invoke(User $actor, Property $property, PosOutlet $outlet, array $orders): array
    {
        $results = [];

        foreach ($orders as $order) {
            try {
                $results[$order['client_uuid']] = DB::transaction(fn (): string => $this->sync($actor, $property, $outlet, $order));
            } catch (PosRefusedException $refused) {
                $results[$order['client_uuid']] = (string) $refused->refusal;
            }
        }

        return $results;
    }

    /**
     * @param  array{client_uuid: string, snapshot_version: string, shift_id: ?int, lines: list<array{variant_id: int, quantity: int}>, tenders: list<array{tender_type: string, amount: ?string, tendered_amount: ?string}>}  $order
     */
    private function sync(User $actor, Property $property, PosOutlet $outlet, array $order): string
    {
        if (PosOrder::query()->where('property_id', $property->id)->where('client_uuid', $order['client_uuid'])->exists()) {
            return 'replayed';
        }

        $shift = PosShift::query()->where('property_id', $property->id)->where('pos_outlet_id', $outlet->id)->where('status', PosShiftStatus::Open->value)->first();

        if ($shift === null) {
            throw new PosRefusedException('outlet_closed');
        }

        // A sale may only be priced from a menu issued during the open shift, so an earlier menu cannot be replayed.
        $snapshot = PosMenuSnapshot::query()
            ->where('pos_outlet_id', $outlet->id)
            ->where('version', $order['snapshot_version'])
            ->where('issued_at', '>=', max(now()->subDays(IssuePosMenuSnapshot::RETENTION_DAYS), $shift->opened_at))
            ->first();

        if ($snapshot === null) {
            throw new PosRefusedException('snapshot_expired');
        }

        $diningOption = PosDiningOption::query()->where('pos_outlet_id', $outlet->id)->where('active', true)->orderByDesc('is_default')->firstOrFail();
        $prices = $this->snapshotPrices($snapshot->payload);

        $created = ($this->createPosOrder)($actor, $property, $shift, $diningOption, $order['client_uuid']);

        if ($created->isRefused()) {
            throw new PosRefusedException((string) $created->refusal);
        }

        $posOrder = $created->model instanceof PosOrder ? $created->model : throw new PosRefusedException('order_invalid');
        $posOrder->forceFill([
            'snapshot_version' => $snapshot->version,
            'synced_late' => $order['shift_id'] !== null && $order['shift_id'] !== $shift->id,
        ])->save();

        foreach ($order['lines'] as $line) {
            $price = $prices[$line['variant_id']] ?? throw new PosRefusedException('variant_not_in_snapshot');

            if ($price['blocked'] !== null) {
                throw new PosRefusedException($price['blocked']);
            }

            // A snapshot price below the live price would give the guest a discount no manager approved, so it waits for one.
            if ($this->livePriceAbove($posOrder, $line['variant_id'], $price['amount'])) {
                throw new PosRefusedException('price_drift_approval_required');
            }

            $this->logDriftIfAny($property, $posOrder, $line['variant_id'], $price['amount']);

            $added = ($this->addOrderLine)($actor, $property, $posOrder, new PosOrderLineInputData(variantId: $line['variant_id'], quantity: $line['quantity'], snapshotPrice: $price['amount']));

            if ($added->isRefused()) {
                throw new PosRefusedException((string) $added->refusal);
            }
        }

        $grandTotal = (string) $posOrder->refresh()->grand_total;
        $tenders = array_map(function (array $tender) use ($outlet, $grandTotal, $order): PosTenderInputData {
            $type = PosTenderType::tryFrom($tender['tender_type']);

            if (! in_array($type, [PosTenderType::Cash, PosTenderType::DuitNowQr], true)) {
                throw new PosRefusedException('tender_not_offline');
            }

            $methodId = PosOutletPaymentMethod::query()->where('pos_outlet_id', $outlet->id)->where('tender_type', $type->value)->where('active', true)->value('payment_method_id');

            if ($methodId === null) {
                throw new PosRefusedException('payment_method_invalid');
            }

            // The register sends no amount for a single tender: the server's grand total, priced from the snapshot, is what is taken.
            $amount = $tender['amount'] ?? (count($order['tenders']) === 1 ? $grandTotal : null);

            if ($amount === null) {
                throw new PosRefusedException('amount_required');
            }

            return new PosTenderInputData($type, $amount, paymentMethodId: $methodId, tenderedAmount: $tender['tendered_amount'] ?? ($type === PosTenderType::Cash ? $amount : null));
        }, $order['tenders']);

        $settled = ($this->settlePosOrder)($actor, $property, $posOrder->refresh(), $tenders);

        if ($settled->isRefused()) {
            throw new PosRefusedException((string) $settled->refusal);
        }

        PosOrderPayment::query()
            ->where('pos_order_id', $posOrder->id)
            ->where('tender_type', PosTenderType::DuitNowQr->value)
            ->update(['reconciliation_status' => PosReconciliationStatus::PendingVerification->value]);

        activity()->performedOn($posOrder)->causedBy($actor)->event('pos.offline_order_synced')
            ->withProperties(['property_id' => $property->id, 'snapshot_version' => $snapshot->version, 'synced_late' => $posOrder->synced_late, 'synced_at' => CarbonImmutable::now()->toIso8601String()])
            ->log('POS offline order synced');

        return 'synced';
    }

    /**
     * What each variant of the snapshot sold for, and why it cannot be sold offline (`blocked`), as the server reads it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{amount: string, blocked: ?string}>
     */
    private function snapshotPrices(array $payload): array
    {
        $prices = [];

        foreach ($payload['categories'] ?? [] as $category) {
            foreach ($category['items'] ?? [] as $item) {
                $requiresChoice = false;

                foreach ($item['modifierGroups'] ?? [] as $group) {
                    $requiresChoice = $requiresChoice || (int) $group['minSelect'] > 0;
                }

                foreach ($item['variants'] ?? [] as $variant) {
                    $blocked = match (true) {
                        (bool) $item['isCombo'] => 'combo_offline',
                        $requiresChoice => 'modifier_offline',
                        (bool) $variant['soldOut'] => 'sold_out',
                        (string) $variant['pricingType'] === PosPricingType::Variable->value => 'variable_price_offline',
                        default => null,
                    };

                    $prices[(int) $variant['id']] = ['amount' => (string) $variant['price'], 'blocked' => $blocked];
                }
            }
        }

        return $prices;
    }

    private function livePriceAbove(PosOrder $order, int $variantId, string $snapshotPrice): bool
    {
        $live = PosOutletItem::query()->where('pos_outlet_id', $order->pos_outlet_id)->where('pos_item_variant_id', $variantId)->value('price_override')
            ?? PosItemVariant::query()->whereKey($variantId)->value('price');

        return $live !== null && BigDecimal::of((string) $live)->isGreaterThan(BigDecimal::of($snapshotPrice));
    }

    private function logDriftIfAny(Property $property, PosOrder $order, int $variantId, string $snapshotPrice): void
    {
        $live = PosOutletItem::query()->where('pos_outlet_id', $order->pos_outlet_id)->where('pos_item_variant_id', $variantId)->value('price_override')
            ?? PosItemVariant::query()->whereKey($variantId)->value('price');

        if ($live === null || (string) $live === $snapshotPrice) {
            return;
        }

        activity()->performedOn($order)->event('pos.offline_price_drift')
            ->withProperties(['property_id' => $property->id, 'pos_item_variant_id' => $variantId, 'snapshot_price' => $snapshotPrice, 'live_price' => (string) $live])
            ->log('Offline price differs from the live price');
    }
}
