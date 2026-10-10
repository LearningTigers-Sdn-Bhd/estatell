<?php

namespace App\Services\Folio;

/**
 * One line of the tax summary. `numbers` are the distinct registration numbers of the taxes the line sums.
 */
final readonly class DocumentTaxLine
{
    /**
     * @param  list<string>  $numbers
     */
    public function __construct(
        public string $label,
        public string $amount,
        public array $numbers = [],
    ) {}
}
