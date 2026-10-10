<?php

namespace App\Services\Folio;

/**
 * A heading with its rows. The heading is null for the one list by date.
 */
final readonly class DocumentLineGroup
{
    /**
     * @param  list<DocumentLineRow>  $rows
     */
    public function __construct(
        public ?string $heading,
        public array $rows,
    ) {}
}
