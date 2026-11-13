<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * A document the guest can open as a PDF: the booking summary, a payment receipt or the final invoice.
 */
class ConciergeDocumentData extends Data
{
    public function __construct(
        public string $label,
        public string $url,
    ) {}
}
