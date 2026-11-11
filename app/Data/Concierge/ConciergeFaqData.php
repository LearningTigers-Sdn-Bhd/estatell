<?php

namespace App\Data\Concierge;

use App\Models\PropertyFaq;
use Spatie\LaravelData\Data;

class ConciergeFaqData extends Data
{
    public function __construct(
        public int $id,
        public string $question,
        public string $answer,
    ) {}

    public static function fromModel(PropertyFaq $faq): self
    {
        return new self($faq->id, $faq->question, $faq->answer);
    }
}
