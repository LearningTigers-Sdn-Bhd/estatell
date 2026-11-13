<?php

namespace App\Actions\Concierge;

use App\Actions\GuestRequests\GuestRequestResult;
use App\Actions\GuestRequests\OpenGuestRequest;
use App\Enums\GuestRequestRefusal;
use App\Enums\GuestRequestSource;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestRequestType;
use App\Models\GuestRequest;
use App\Models\ReservationUnit;

class SubmitConciergeRequest
{
    /** Open requests one stay may have, so the page cannot fill the staff queue. */
    public const int MAX_OPEN = 5;

    public function __construct(private OpenGuestRequest $openRequest) {}

    /**
     * A guest asks for something from their own page. The request has no staff opener and the source Concierge, and it
     * lands in the same queue as a request staff record. Refused, writing nothing, with `too_many_open` while the stay
     * has `MAX_OPEN` open requests, and with the refusals of `OpenGuestRequest` (the stay must be checked in).
     */
    public function __invoke(ReservationUnit $stay, GuestRequestType $type, string $details): GuestRequestResult
    {
        $open = GuestRequest::query()
            ->where('property_id', $stay->property_id)
            ->where('reservation_unit_id', $stay->id)
            ->whereIn('status', [GuestRequestStatus::Open, GuestRequestStatus::InProgress])
            ->count();

        if ($open >= self::MAX_OPEN) {
            return GuestRequestResult::refused(GuestRequestRefusal::TooManyOpen);
        }

        return ($this->openRequest)(null, $stay->property()->firstOrFail(), $type, GuestRequestSource::Concierge, $details, $stay, null);
    }
}
