<?php

namespace App\Actions\Concierge;

use App\Actions\Kiosk\IssueBookingPreCheckInToken;
use App\Events\StayCheckedIn;
use App\Features\Concierge;
use App\Models\ReservationUnit;
use App\Notifications\ConciergeStayLink;
use App\Services\Kiosk\KioskStays;
use App\Services\Notifications\MailActorResolver;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

class SendConciergeStayLink
{
    public function __construct(private IssueBookingPreCheckInToken $issueToken, private KioskStays $stays) {}

    /**
     * When a stay checks in, email the guest the link to their stay page. Run by the `StayCheckedIn` event, whatever
     * channel checked the guest in. Nothing is sent, and no link is made, while the property has the Concierge feature off,
     * or when the stay's guest has no email address. A new link is made for each email; the old ones stay valid until they expire.
     */
    public function __invoke(StayCheckedIn $event): void
    {
        $stay = ReservationUnit::query()->where('property_id', $event->propertyId)->with(['property.settings'])->find($event->reservationUnitId);

        if ($stay === null || ! Feature::for($stay->property)->active(Concierge::class)) {
            return;
        }

        $guest = $this->stays->guest($stay);

        if ($guest === null || blank($guest->email)) {
            return;
        }

        $url = ($this->issueToken)($stay);

        Context::scope(fn () => Notification::route('mail', $guest->email)->notify(new ConciergeStayLink(
            $stay->property->name,
            Str::of($guest->full_name)->trim()->before(' ')->toString() ?: __('guest'),
            $stay->arrival_date->toDateString(),
            $stay->departure_date->toDateString(),
            $url,
        )), hidden: [MailActorResolver::PROPERTY_CONTEXT_KEY => $stay->property_id]);
    }
}
