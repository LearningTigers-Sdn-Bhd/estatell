<?php

namespace App\Data\Concierge;

use App\Models\PropertyInfoTopic;
use Spatie\LaravelData\Data;

class ConciergeTopicData extends Data
{
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
    ) {}

    public static function fromModel(PropertyInfoTopic $topic): self
    {
        return new self($topic->id, $topic->title, $topic->body);
    }
}
