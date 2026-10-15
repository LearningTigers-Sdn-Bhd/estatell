<?php

namespace App\Models;

use App\Enums\OnboardingStep;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A section an admin flagged for the owner when sending a go-live request back.
 *
 * @property int $id
 * @property int $go_live_review_id
 * @property OnboardingStep $step
 */
#[Fillable(['go_live_review_id', 'step'])]
class GoLiveReviewFlag extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'step' => OnboardingStep::class,
        ];
    }

    /** @return BelongsTo<GoLiveReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(GoLiveReview::class, 'go_live_review_id');
    }
}
