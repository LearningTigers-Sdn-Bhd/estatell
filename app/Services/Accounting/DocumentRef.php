<?php

namespace App\Services\Accounting;

/**
 * A document in the accounting software: its id and, where the provider needs it to match documents, its own type.
 */
final readonly class DocumentRef
{
    public function __construct(public string $id, public ?string $type = null) {}
}
