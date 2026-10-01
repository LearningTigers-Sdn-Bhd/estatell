<?php

namespace App\Services\NightAudit;

/**
 * A number taken from a document sequence, with the year it belongs to and its formatted reference.
 */
readonly class IssuedDocumentNumber
{
    public function __construct(
        public int $number,
        public int $year,
        public string $reference,
    ) {}
}
