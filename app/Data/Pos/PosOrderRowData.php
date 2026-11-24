<?php

namespace App\Data\Pos;

use App\Models\PosOrder;
use Spatie\LaravelData\Data;

/**
 * One receipt on the finance orders list.
 */
class PosOrderRowData extends Data
{
    public function __construct(
        public int $id,
        public ?string $orderNumber,
        public string $orderType,
        public string $status,
        public string $statusLabel,
        public string $outletName,
        public ?string $businessDate,
        public string $grandTotal,
        public string $folioPostedAmount,
        public bool $onEInvoice,
        public ?string $settledAt,
    ) {}

    public static function fromModel(PosOrder $order): self
    {
        return new self($order->id, $order->order_number, $order->order_type->value, $order->status->value, $order->status->label(), $order->outlet->name, $order->business_date?->toDateString(), $order->grand_total, $order->folio_posted_amount, $order->e_invoice_submission_id !== null, $order->settled_at?->toIso8601String());
    }
}
