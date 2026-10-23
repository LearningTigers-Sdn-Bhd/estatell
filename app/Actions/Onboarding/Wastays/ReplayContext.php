<?php

namespace App\Actions\Onboarding\Wastays;

use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\Wastays\WastaysBookingIndex;
use App\Services\Onboarding\Wastays\WastaysDocumentNumbers;

/**
 * What one replayed day works with: the property, the admin, the date, the bundle lookups and the summary.
 */
final readonly class ReplayContext
{
    public function __construct(
        public Property $property,
        public User $actor,
        public string $date,
        public WastaysBookingIndex $index,
        public WastaysDocumentNumbers $numbers,
        public WastaysReplaySummary $summary,
    ) {}
}
