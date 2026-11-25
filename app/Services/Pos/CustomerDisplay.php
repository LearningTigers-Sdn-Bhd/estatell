<?php

namespace App\Services\Pos;

use App\Data\Property\CurrencyData;
use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Features\EInvoicing;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\PosOrderPayment;
use App\Models\PosRegister;
use App\Models\Property;
use App\Services\EInvoice\PosOrderReportLines;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

/**
 * What the customer-facing display shows: the idle welcome, the open check the cashier is ringing up, the checkout state
 * with its static DuitNow QR, or the thank-you screen for a just-settled sale. Reads only; the version a screen polls is
 * written by `BumpCfdVersion`. Never exposes internal notes, kitchen state, costs, staff or guest details, and never shows a
 * settled order once the thank-you window has passed. See docs/34-native-pos/10-customer-facing-display.md.
 */
class CustomerDisplay
{
    /** How long the thank-you screen stays up after settlement. */
    public const int THANK_YOU_SECONDS = 30;

    public static function versionKey(int $registerId): string
    {
        return 'pos:cfd:'.$registerId.':version';
    }

    public function version(int $registerId): string
    {
        return (string) Cache::get(self::versionKey($registerId), '0');
    }

    /**
     * @return array<string, mixed>
     */
    public function stateFor(Property $property, PosRegister $register): array
    {
        $register->loadMissing('outlet');
        $outlet = $register->outlet;
        $order = $this->activeOrder($property, $register);
        $mode = $this->mode($register, $order);

        return [
            'mode' => $mode,
            'propertyName' => $property->name,
            'propertyLogoUrl' => $property->getFirstMedia(Property::LOGO) === null ? null : route('property.logo', ['property' => $property->code]),
            'outletName' => $outlet->name,
            'registerName' => $register->name,
            'welcomeMessage' => $outlet->cfd_welcome_message,
            'duitnowQrUrl' => $outlet->cfd_duitnow_qr_path === null ? null : Storage::disk(config('filesystems.public_media'))->url($outlet->cfd_duitnow_qr_path),
            'currency' => CurrencyData::forProperty($property),
            'order' => $mode === 'idle' || $order === null ? null : $this->order($property, $order, $mode),
        ];
    }

    private function mode(PosRegister $register, ?PosOrder $order): string
    {
        if ($order === null) {
            return 'idle';
        }

        return match ($order->status) {
            PosOrderStatus::Open => $order->lines->isEmpty() ? 'idle' : ($register->cfd_checkout_active ? 'checkout' : 'active'),
            PosOrderStatus::Settled => $order->settled_at === null || $order->settled_at->diffInSeconds(now()) > self::THANK_YOU_SECONDS ? 'idle' : 'settled',
            default => 'idle',
        };
    }

    private function activeOrder(Property $property, PosRegister $register): ?PosOrder
    {
        if ($register->active_pos_order_id === null) {
            return null;
        }

        return PosOrder::query()
            ->where('property_id', $property->id)
            ->where('pos_outlet_id', $register->pos_outlet_id)
            ->with(['diningOption', 'table', 'lines.modifiers', 'lines.variant.item', 'payments'])
            ->find($register->active_pos_order_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function order(Property $property, PosOrder $order, string $mode): array
    {
        $rounding = BigDecimal::of($order->rounding_adjustment);
        $lines = $order->lines->filter(fn (PosOrderLine $line): bool => $line->kitchen_status !== PosKitchenStatus::Voided);
        $settled = $mode === 'settled';
        $payments = $settled ? $order->payments : collect();

        return [
            'orderNumber' => $order->order_number,
            'diningOptionName' => $order->diningOption->name,
            'tableCode' => $order->table?->code,
            'subtotal' => $order->subtotal,
            'discountTotal' => $order->discount_total,
            'serviceChargeTotal' => $order->service_charge_total,
            'taxTotal' => $order->tax_total,
            'roundingAdjustment' => $order->rounding_adjustment,
            'grandTotal' => $order->grand_total,
            'netPayable' => BigDecimal::of($order->grand_total)->plus($rounding)->toScale(2)->__toString(),
            'lines' => array_values($lines->map(fn (PosOrderLine $line): array => $this->line($line))->all()),
            'payments' => array_values($payments->map(fn (PosOrderPayment $payment): array => [
                'tender' => $payment->tender_type->label(),
                'amount' => $payment->amount,
                'tendered' => $payment->tendered_amount,
                'change' => $payment->change_amount,
            ])->all()),
            'totalChange' => array_reduce($payments->all(), fn (BigDecimal $sum, PosOrderPayment $payment): BigDecimal => $sum->plus($payment->change_amount ?? '0.00'), BigDecimal::zero())->toScale(2)->__toString(),
            'claimQrSvg' => $this->claimQrSvg($property, $order, $settled),
            'settledAt' => $order->settled_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(PosOrderLine $line): array
    {
        $item = $line->variant?->item;

        return [
            'id' => $line->id,
            'itemName' => $line->item_name,
            'variantName' => $line->variant_name,
            'quantity' => (string) $line->quantity,
            'isWeighed' => $item !== null && $item->sold_by_weight,
            'weightUnit' => $item?->sold_by_weight ? $item->weight_unit : null,
            'weightUnitDivisor' => $item?->sold_by_weight ? $item->weight_unit_divisor : null,
            'modifiers' => array_values($line->modifiers->map(fn ($modifier): string => ($modifier->quantity > 1 ? $modifier->quantity.'× ' : '').$modifier->option_name)->all()),
            'lineGross' => $line->line_gross,
            'discountAmount' => $line->discount_amount,
            'isEntitlementCovered' => $line->is_entitlement_covered,
            'isComboPart' => $line->parent_combo_line_id !== null,
        ];
    }

    private function claimQrSvg(Property $property, PosOrder $order, bool $settled): ?string
    {
        if (! $settled || ! Feature::for($property)->active(EInvoicing::class) || ! app(PosOrderReportLines::class)->reportable($order)) {
            return null;
        }

        $url = app(PosReceiptClaimQr::class)->url($order);

        return $url === null ? null : app(PosReceiptClaimQr::class)->svg($url);
    }
}
