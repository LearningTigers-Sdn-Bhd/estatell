<?php

namespace App\Data\Guests;

use App\Models\GuestNote;
use Spatie\LaravelData\Data;

/**
 * One note on the guest page. Expects the `author` relation to be loaded.
 */
class GuestNoteData extends Data
{
    public function __construct(
        public int $id,
        public string $body,
        public ?string $authorName,
        public string $createdAt,
    ) {}

    public static function fromModel(GuestNote $note): self
    {
        return new self(
            id: $note->id,
            body: $note->body,
            authorName: $note->author?->name,
            createdAt: $note->created_at->toIso8601String(),
        );
    }
}
