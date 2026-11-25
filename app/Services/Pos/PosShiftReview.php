<?php

namespace App\Services\Pos;

use App\Data\NightAudit\PosShiftReviewData;
use App\Enums\PosOrderStatus;
use App\Enums\PosShiftStatus;
use App\Features\Pos;
use App\Models\PosOrder;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * The POS shifts and orders still open, for the night audit sheet. A non-blocking warning: a late shift keeps
 * trading across the audit and its orders settle on the new business date. Null when POS is off or nothing is open.
 */
class PosShiftReview
{
    public function for(Property $property, User $user): ?PosShiftReviewData
    {
        if (! Feature::for($property)->active(Pos::class)) {
            return null;
        }

        $shifts = PosShift::query()->where('property_id', $property->id)->where('status', '!=', PosShiftStatus::Closed->value)->count();
        $orders = PosOrder::query()->where('property_id', $property->id)->where('status', PosOrderStatus::Open->value)->count();

        if ($shifts === 0 && $orders === 0) {
            return null;
        }

        return new PosShiftReviewData(
            trans_choice(':count POS shift is still open|:count POS shifts are still open', $shifts, ['count' => $shifts]).' '.trans_choice(':count order is still open.|:count orders are still open.', $orders, ['count' => $orders]),
            null,
        );
    }
}
