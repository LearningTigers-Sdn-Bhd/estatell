<?php

namespace App\Models;

use App\Enums\OnboardingStep;
use Carbon\CarbonImmutable;
use Database\Factories\OnboardingSkipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An owner's skip of an optional onboarding step, or of Taxes with "We don't charge tax". OnboardingProgress reads it.
 *
 * @property int $id
 * @property int $property_id
 * @property OnboardingStep $step
 * @property int|null $skipped_by
 * @property CarbonImmutable $skipped_at
 */
#[Fillable(['property_id', 'step', 'skipped_by', 'skipped_at'])]
class OnboardingSkip extends Model
{
    /** @use HasFactory<OnboardingSkipFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'step' => OnboardingStep::class,
            'skipped_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<User, $this> */
    public function skippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'skipped_by');
    }
}
