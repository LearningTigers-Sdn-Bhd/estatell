<?php

namespace App\Services\Concierge;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The QR code a property prints for its lobby: the address of its Concierge lobby page. It is made on the server from
 * that address alone, as an SVG data URI for an image tag, so it can be printed at any size. It holds no guest data.
 */
class ConciergeLobbyQr
{
    public function dataUri(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($url));
    }
}
