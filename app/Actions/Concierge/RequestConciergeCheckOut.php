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

class RequestConciergeCheckOut
{
    /** A check-out request is a request of the type Other whose details start with this text, so staff see what it is. */
    public const string PREFIX = 'Check-out request';

    public function __construct(private OpenGuestRequest $openRequest) {}

    /**
     * A guest who cannot check out alone (money is owed, a deposit is held, or it is not yet the departure day) asks the front
     * desk to check them out, with an optional note such as "we leave at 10 am and need a taxi". It lands in the staff
     * Requests list. Refused, writing nothing, while the stay is not checked in, and while an earlier check-out request of
     * the stay is still open.
     */
    public function __invoke(ReservationUnit $stay, ?string $note): GuestRequestResult
    {
        if ($stay->status !== UnitStatus::CheckedIn) {
            return GuestRequestResult::refused(GuestRequestRefusal::StayNotInHouse);
        }

        if ($this->open($stay) !== null) {
            return GuestRequestResult::refused(GuestRequestRefusal::TooManyOpen);
        }

        $details = self::PREFIX.(filled($note) ? ': '.trim((string) $note) : '');

        return ($this->openRequest)(null, $stay->property()->firstOrFail(), GuestRequestType::Other, GuestRequestSource::Concierge, $details, $stay, null);
    }

    /**
     * The check-out request of the stay that is still open, if any.
     */
    public function open(ReservationUnit $stay): ?GuestRequest
    {
        return GuestRequest::query()
            ->where('property_id', $stay->property_id)
            ->where('reservation_unit_id', $stay->id)
            ->where('type', GuestRequestType::Other)
            ->where('details', 'like', self::PREFIX.'%')
            ->whereIn('status', [GuestRequestStatus::Open, GuestRequestStatus::InProgress])
            ->first();
    }
}
