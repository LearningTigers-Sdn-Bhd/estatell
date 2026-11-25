<?php

namespace App\Data\Pos;

use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use Spatie\LaravelData\Data;

/**
 * One guest check as the register ticket and the receipt show it. Voided lines stay in `lines` (marked) so a receipt can
 * show them; the totals are the server's.
 */
class PosOrderData extends Data
{
    /**
     * @param  list<PosOrderLineData>  $lines
     * @param  list<array{id: int, tender: string, direction: string, amount: string, tendered: string|null, change: string|null, reference: string|null, status: string, paidAt: string}>  $payments
     * @param  list<array{name: string, amount: string, reason: string|null}>  $discounts
     */
    public function __construct(
        public int $id,
        public ?string $orderNumber,
        public string $orderType,
        public string $status,
        public string $statusLabel,
        public string $outletName,
        public ?string $registerName,
        public ?string $businessDate,
        public int $diningOptionId,
        public string $diningOptionName,
        public int $guestCount,
        public ?string $roomLabel,
        public string $subtotal,
        public string $discountTotal,
        public string $serviceChargeTotal,
        public string $taxTotal,
        public string $roundingAdjustment,
        public string $grandTotal,
        public string $folioPostedAmount,
        public int $prebillPrintCount,
        public ?string $orderNote,
        public ?int $refundForOrderId,
        public ?string $refundForOrderNumber,
        public ?string $cashier,
        public ?string $openedAt,
        public ?string $settledAt,
        public ?int $eInvoiceSubmissionId,
        public array $lines,
        public array $payments,
        public array $discounts,
    ) {}

    /**
     * Expects `outlet`, `shift.register`, `diningOption`, `reservationUnit.reservation`, `refundFor`, `openedBy`, `lines.modifiers`,
     * `payments` and `discounts` loaded.
     *
     * @param  array<int, string>  $refunded  Refunded quantity by original line id.
     */
    public static function fromModel(PosOrder $order, array $refunded = []): self
    {
        return new self(
            $order->id,
            $order->order_number,
            $order->order_type->value,
            $order->status->value,
            $order->status->label(),
            $order->outlet->name,
            $order->shift?->register?->name,
            $order->business_date?->toDateString(),
            $order->pos_dining_option_id,
            $order->diningOption->name,
            $order->guest_count,
            $order->reservationUnit?->reservation?->reference,
            $order->subtotal,
            $order->discount_total,
            $order->service_charge_total,
            $order->tax_total,
            $order->rounding_adjustment,
            $order->grand_total,
            $order->folio_posted_amount,
            $order->prebill_print_count,
            $order->order_note,
            $order->refund_for_order_id,
            $order->refundFor?->order_number,
            $order->openedBy?->name,
            $order->opened_at->toIso8601String(),
            $order->settled_at?->toIso8601String(),
            $order->e_invoice_submission_id,
            array_values($order->lines->map(fn ($line): PosOrderLineData => PosOrderLineData::fromModel($line, $refunded[$line->id] ?? null))->all()),
            array_values($order->payments->map(fn (PosOrderPayment $payment): array => [
                'id' => $payment->id,
                'tender' => $payment->tender_type->label(),
                'direction' => $payment->direction->value,
                'amount' => $payment->amount,
                'tendered' => $payment->tendered_amount,
                'change' => $payment->change_amount,
                'reference' => $payment->reference,
                'status' => $payment->status->value,
                'paidAt' => $payment->paid_at->toIso8601String(),
            ])->all()),
            array_values($order->discounts->map(fn ($discount): array => ['name' => $discount->name, 'amount' => $discount->amount, 'reason' => $discount->reason])->all()),
        );
    }
}
