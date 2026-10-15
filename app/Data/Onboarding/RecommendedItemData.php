<?php

namespace App\Data\Onboarding;

use Spatie\LaravelData\Data;

/**
 * One row of the Recommended next checklist: its state, a one-line summary built on the server, and the link to act on.
 */
class RecommendedItemData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public string $summary,
        public bool $done,
        public string $actionLabel,
        public string $actionUrl,
        public bool $dismissible,
    ) {}
}
