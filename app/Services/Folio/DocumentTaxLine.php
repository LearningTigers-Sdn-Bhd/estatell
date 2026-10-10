<?php

namespace App\Services\Folio;

final readonly class DocumentTaxLine
{
    public function __construct(
        public string $label,
        public string $amount,
    ) {}
}
