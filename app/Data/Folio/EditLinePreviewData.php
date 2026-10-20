<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * What the Edit line Dialog shows for typed values: the charge now (`before`) and with the new values (`after`), each
 * as net, tax and total with tax, the difference, the tax lines of the new values, or the refusal and the field it
 * belongs to. All figures are server text.
 */
class EditLinePreviewData extends Data
{
    /**
     * @param  list<ManualTaxData>  $taxes
     */
    public function __construct(
        public string $beforeNet,
        public string $beforeTax,
        public string $beforeTotal,
        public ?string $afterNet,
        public ?string $afterTax,
        public ?string $afterTotal,
        public ?string $difference,
        public array $taxes,
        public ?string $refusal,
        public ?string $field,
    ) {}
}
