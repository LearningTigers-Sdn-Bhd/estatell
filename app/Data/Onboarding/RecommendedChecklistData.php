<?php

namespace App\Data\Onboarding;

use Spatie\LaravelData\Data;

/**
 * The Recommended next checklist: the items that are not dismissed, in fixed order, and how many are done.
 */
class RecommendedChecklistData extends Data
{
    /**
     * @param  list<RecommendedItemData>  $items
     */
    public function __construct(
        public int $done,
        public int $total,
        public array $items,
    ) {}
}
