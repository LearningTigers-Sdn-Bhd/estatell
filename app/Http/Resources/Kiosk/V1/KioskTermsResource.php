<?php

namespace App\Http\Resources\Kiosk\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `{ terms_version, terms_text }`: the terms of the registration card, given as `['version' => int, 'text' => string]`.
 *
 * @property array{version: int, text: string} $resource
 */
class KioskTermsResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['terms_version' => $this->resource['version'], 'terms_text' => $this->resource['text']];
    }
}
