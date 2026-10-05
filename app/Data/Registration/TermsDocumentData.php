<?php

namespace App\Data\Registration;

use App\Models\TermsDocument;
use Spatie\LaravelData\Data;

/** One terms document in the document Select of the Terms tab. */
class TermsDocumentData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $isDefault,
        public bool $canDelete,
    ) {}

    public static function fromModel(TermsDocument $document): self
    {
        return new self(
            id: $document->id,
            name: $document->name,
            isDefault: $document->is_default,
            canDelete: ! $document->is_default && ! $document->room_types_exists,
        );
    }
}
