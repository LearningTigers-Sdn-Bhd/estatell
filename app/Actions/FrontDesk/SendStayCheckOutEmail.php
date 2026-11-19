<?php

namespace App\Actions\FrontDesk;

use App\Actions\Kiosk\IssueBookingPreCheckInToken;
use App\Enums\UnitStatus;
use App\Features\Concierge;
use App\Models\ReservationUnit;
use App\Notifications\StayCheckedOutThanks;
use App\Services\Kiosk\KioskStays;
use App\Services\Notifications\MailActorResolver;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

/**
 * The thank-you of a checked-out stay: one email to the guest on the stay. It is the same for a check-out at the front desk and one on
 * the Concierge page, as both run `CheckOutUnit`, which queues `SendStayCheckOutEmailJob`. Nothing is sent when the stay is not checked
 * out (an undo ran first) or the guest has no email. The link to the stay page, where the final invoice and receipts are, is made only
 * while the property has the Concierge feature on. A stay entered after it ended (a past or migrated stay) never gets one.
 */
class SendStayCheckOutEmail
{
    public function __construct(private IssueBookingPreCheckInToken $issueToken, private KioskStays $stays) {}

    public function __invoke(int $propertyId, int $reservationUnitId): void
    {
        $stay = ReservationUnit::query()->where('property_id', $propertyId)->with(['property.settings', 'reservation'])->find($reservationUnitId);

        if ($stay === null || $stay->status !== UnitStatus::CheckedOut) {
            return;
        }

        $guest = $this->stays->guest($stay);

        if ($guest === null || blank($guest->email)) {
            return;
        }

        $timeZone = $stay->property->settings->time_zone ?? config('app.timezone');
        $url = Feature::for($stay->property)->active(Concierge::class) ? ($this->issueToken)($stay) : null;

        Context::scope(fn () => Notification::route('mail', $guest->email)->notify(new StayCheckedOutThanks(
            $stay->property->name,
            Str::of($guest->full_name)->trim()->before(' ')->toString() ?: __('guest'),
            $stay->reservation->reference,
            $stay->arrival_date->toDateString(),
            ($stay->checked_out_at ?? now())->setTimezone($timeZone)->toDateString(),
            $url,
        )), hidden: [MailActorResolver::PROPERTY_CONTEXT_KEY => $stay->property_id]);
    }
}
