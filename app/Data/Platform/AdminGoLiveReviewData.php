<?php

namespace App\Data\Platform;

use App\Data\Onboarding\GoLiveReviewData;
use App\Models\GoLiveReview;
use App\Models\Property;
use Spatie\LaravelData\Data;

/**
 * The admin review page of a pending go-live request: who sent it and when, the Review rows with their extra lines,
 * the default opening date, and the property's owner and partner.
 */
class AdminGoLiveReviewData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $partnerName,
        public string $submittedBy,
        public string $sentOn,
        public string $today,
        public GoLiveReviewData $review,
    ) {}

    public static function fromReview(Property $property, GoLiveReview $request, string $today, GoLiveReviewData $review): self
    {
        return new self(
            code: $property->code,
            name: $property->name,
            partnerName: $property->partner?->name,
            submittedBy: $request->submitter->name ?? __('Unknown'),
            sentOn: $request->submitted_at->translatedFormat('j M Y, H:i'),
            today: $today,
            review: $review,
        );
    }
}
