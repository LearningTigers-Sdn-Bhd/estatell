<?php

namespace App\Data\Onboarding;

use App\Models\GoLiveReview;
use Spatie\LaravelData\Data;

/**
 * A go-live request as the owner sees it on Review: sent and waiting, or sent back with the admin's note.
 */
class GoLiveRequestData extends Data
{
    public function __construct(
        public string $sentOn,
        public ?string $note,
        public ?string $reviewedOn,
    ) {}

    public static function fromReview(GoLiveReview $review): self
    {
        return new self(
            sentOn: $review->submitted_at->translatedFormat('j M Y'),
            note: $review->note,
            reviewedOn: $review->decided_at?->translatedFormat('j M Y'),
        );
    }
}
