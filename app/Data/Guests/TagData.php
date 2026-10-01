<?php

namespace App\Data\Guests;

use App\Models\Tag;
use Spatie\LaravelData\Data;

class TagData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public static function fromModel(Tag $tag): self
    {
        return new self(id: $tag->id, name: $tag->name);
    }
}
