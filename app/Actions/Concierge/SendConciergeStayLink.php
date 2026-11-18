<?php

namespace App\Actions\Concierge;

use App\Actions\Kiosk\IssueBookingPreCheckInToken;
use App\Actions\Webhooks\TriggerWebhook;
use App\Enums\WebhookEvent;
use App\Features\Concierge;
use App\Models\ReservationUnit;
use App\Models\WebhookEndpoint;
use App\Notifications\ConciergeStayLink;
use App\Services\Kiosk\KioskPin;
use App\Services\Kiosk\KioskPinStatus;
use App\Services\Kiosk\KioskStays;
use App\Services\Notifications\MailActorResolver;
use App\Services\Webhooks\Payloads\GuestWebhookPayload;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

class SendConciergeStayLink
{
    /** How the end of the PIN reads to a guest, in the email and in `valid_until_text`. */
    private const string PIN_TIME_FORMAT = 'D MMM YYYY, h:mm A';

    public function __construct(
        private IssueBookingPreCheckInToken $issueToken,
        private KioskStays $stays,
        private KioskPin $pins,
        private GuestWebhookPayload $guests,
    ) {}

    /**
     * The welcome of a checked-in stay: one email to the guest and one `stay.welcome_ready` webhook (for WhatsApp), each with the
     * link to the stay page and, when `$pin` is ready, the door PIN with its room and end time. Run by `SendStayWelcomeJob` once the
     * PIN is ready, or once it failed or took too long (`$pin` is then null). Nothing is sent, and no link is made, while the property
     * has the Concierge feature off. The email also needs an address on the guest, the webhook an endpoint that hears the event.
     * A new link is made for each welcome; the old ones stay valid until they expire.
     */
    public function __invoke(int $propertyId, int $reservationUnitId, ?KioskPinStatus $pin): void
    {
        $stay = ReservationUnit::query()->where('property_id', $propertyId)->with(['property.settings'])->find($reservationUnitId);

        if ($stay === null || ! Feature::for($stay->property)->active(Concierge::class)) {
            return;
        }

        $guest = $this->stays->guest($stay);
        $email = $guest === null || blank($guest->email) ? null : $guest->email;
        $hooked = WebhookEndpoint::query()->listeningFor(WebhookEvent::StayWelcomeReady, $propertyId)->exists();

        if ($email === null && ! $hooked) {
            return;
        }

        $url = ($this->issueToken)($stay);
        $room = $this->stays->roomNumber($stay);
        $pin = $pin?->status === KioskPinStatus::READY ? $pin : null;
        $timeZone = $stay->property->settings->time_zone;

        if ($email !== null) {
            Context::scope(fn () => Notification::route('mail', $email)->notify(new ConciergeStayLink(
                $stay->property->name,
                Str::of($guest->full_name)->trim()->before(' ')->toString() ?: __('guest'),
                $stay->arrival_date->toDateString(),
                $stay->departure_date->toDateString(),
                $url,
                $room,
                $pin?->code,
                $pin?->validUntil?->setTimezone($timeZone)->isoFormat(self::PIN_TIME_FORMAT),
                $pin === null && $this->pins->canRetry($stay),
            )), hidden: [MailActorResolver::PROPERTY_CONTEXT_KEY => $stay->property_id]);
        }

        if ($hooked) {
            app(TriggerWebhook::class)(WebhookEvent::StayWelcomeReady, $propertyId, ReservationUnit::class, $stay->id, ['stay' => [
                'url' => $url,
                'room' => $room,
                'guest' => $guest === null ? null : $this->guests->contact($guest),
                'pin' => $pin === null ? null : [
                    'code' => $pin->code,
                    'valid_until' => $pin->validUntil?->setTimezone($timeZone)->toIso8601String(),
                    'valid_until_text' => $pin->validUntil?->setTimezone($timeZone)->isoFormat(self::PIN_TIME_FORMAT),
                ],
            ]]);
        }
    }
}
