<?php

namespace App\Services\Pos;

use App\Models\PosOrder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The link a walk-in receipt prints, and its QR code as an SVG, so a guest can claim an e-invoice from the slip. The token is
 * the order's own and is not shown anywhere else.
 */
class PosReceiptClaimQr
{
    public function url(PosOrder $order): ?string
    {
        return $order->receipt_claim_token === null ? null : route('pos.receipt-claim.show', ['token' => $order->receipt_claim_token]);
    }

    public function svg(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(160, 0), new SvgImageBackEnd));

        return $writer->writeString($url);
    }
}
