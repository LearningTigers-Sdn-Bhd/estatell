<?php

namespace App\Actions\Concierge;

use App\Actions\GuestRequests\GuestRequestResult;
use App\Actions\GuestRequests\OpenGuestRequest;
use App\Enums\GuestRequestRefusal;
use App\Enums\GuestRequestSource;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestRequestType;
use App\Enums\UnitStatus;
use App\Models\GuestRequest;
use App\Models\ReservationUnit;

class RequestConciergeRefund
{
    public function __construct(private OpenGuestRequest $openRequest) {}

    /**
     * A guest who has checked out asks the property for a refund. It becomes a guest request of the type Refund for staff
     * to answer; nothing is refunded here. Refused, writing nothing, while the property has the refund switch off, while
     * the stay is not checked out, and while the stay already has an open refund request.
     */
    public function __invoke(ReservationUnit $stay, string $details): GuestRequestResult
    {
        $property = $stay->property()->with('settings')->firstOrFail();

        if (! $property->settings->concierge_refund_requests_enabled || $stay->status !== UnitStatus::CheckedOut) {
            return GuestRequestResult::refused(GuestRequestRefusal::StayNotInHouse);
        }

        $open = GuestRequest::query()
            ->where('property_id', $stay->property_id)
            ->where('reservation_unit_id', $stay->id)
            ->where('type', GuestRequestType::Refund)
            ->whereIn('status', [GuestRequestStatus::Open, GuestRequestStatus::InProgress])
            ->exists();

        if ($open) {
            return GuestRequestResult::refused(GuestRequestRefusal::TooManyOpen);
        }

        return ($this->openRequest)(null, $property, GuestRequestType::Refund, GuestRequestSource::Concierge, $details, $stay, null);
    }
}
