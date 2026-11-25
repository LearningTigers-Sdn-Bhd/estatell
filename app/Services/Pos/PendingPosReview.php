<?php

namespace App\Services\Pos;

use App\Data\NightAudit\PosReviewData;
use App\Enums\PermissionEnum;
use App\Enums\PosPostingStatus;
use App\Features\Pos;
use App\Models\PosPosting;
use App\Models\Property;
use App\Models\User;
use Brick\Math\BigDecimal;
use Laravel\Pennant\Feature;

/**
 * The POS receipts waiting for front desk, for the night audit sheet. A non-blocking warning: it never stops the audit.
 * Null when POS integration is off or nothing waits. The link shows only to a user who may view POS postings.
 * See docs/33-pos/01-outlets-and-postings.md#night-audit-integration.
 */
class PendingPosReview
{
    public function for(Property $property, User $user): ?PosReviewData
    {
        if (! Feature::for($property)->active(Pos::class)) {
            return null;
        }

        $amounts = PosPosting::query()
            ->where('property_id', $property->id)
            ->where('status', PosPostingStatus::PendingReview)
            ->pluck('amount');

        if ($amounts->isEmpty()) {
            return null;
        }

        $total = $amounts->reduce(fn (BigDecimal $sum, string $amount): BigDecimal => $sum->plus($amount), BigDecimal::zero())->toScale(2);
        $code = $property->settings?->currency->getCurrencyCode();

        return new PosReviewData(
            trans_choice(':count POS posting needs review (:amount)|:count POS postings need review (:amount)', $amounts->count(), ['amount' => trim($code.' '.$total)]),
            $user->can(PermissionEnum::PosView->value) ? route('finance.pos-postings.index', ['property' => $property->code, 'status' => PosPostingStatus::PendingReview->value], absolute: false) : null,
        );
    }
}
