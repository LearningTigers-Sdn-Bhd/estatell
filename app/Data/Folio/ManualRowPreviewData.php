<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * What the manual folio preview says about one row. `total` is the signed folio effect with tax (a charge is positive, a
 * payment negative, a refund positive); it is null when the row is refused. `field` and `refusal` are the form field
 * and message of the first problem.
 */
class ManualRowPreviewData extends Data
{
    /**
     * @param  list<ManualTaxData>  $taxes
     */
    public function __construct(
        public string $key,
        public ?string $total,
        public array $taxes,
        public ?string $field,
        public ?string $refusal,
    ) {}
}
