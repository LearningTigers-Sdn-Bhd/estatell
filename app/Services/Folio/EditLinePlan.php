<?php

namespace App\Services\Folio;

use App\Services\Catalogues\TaxCalculationResult;
use Brick\Money\Money;

/**
 * What `EditLinePreview::plan()` decides for one edit: a refusal with the money now, or the new tax calculation.
 * `before` is the charge with its live tax lines, `unitAmount` the typed unit amount.
 */
class EditLinePlan
{
    public function __construct(
        public readonly ?string $refusal,
        public readonly Money $before,
        public readonly Money $beforeNet,
        public readonly ?Money $unitAmount = null,
        public readonly ?TaxCalculationResult $tax = null,
    ) {}
}
