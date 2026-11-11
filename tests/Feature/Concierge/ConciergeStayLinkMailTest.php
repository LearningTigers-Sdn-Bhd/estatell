<?php

use App\Actions\Concierge\SendConciergeStayLink;
use App\Enums\UnitStatus;
use App\Events\StayCheckedIn;
use App\Features\Concierge;
use App\Models\BookingPreCheckInToken;
use App\Notifications\ConciergeStayLink;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/ConciergeSetup.php';

it('emails the guest the link to their stay when they check in, whichever channel checks them in', function () {
    Notification::fake();
    ['token' => $token, 'guest' => $guest, 'unit' => $unit] = conciergeSetup();
    $guest->forceFill(['email' => 'aisha@example.test'])->save();
    conciergeIdentityDone($guest);

    $this->post("/c/{$token}/check-in");

    Notification::assertSentOnDemand(ConciergeStayLink::class, function (ConciergeStayLink $notification, array $channels, $notifiable) use ($unit) {
        $link = basename($notification->stayUrl);

        return $notifiable->routes['mail'] === 'aisha@example.test'
            && $notification->guestName === 'Aisha'
            && $notification->propertyName === 'Palm Resort'
            && str_starts_with($notification->stayUrl, url('/c/'))
            && BookingPreCheckInToken::query()->where('reservation_unit_id', $unit->id)->where('token_hash', hash('sha256', $link))->exists();
    });
    expect($unit->refresh()->status)->toBe(UnitStatus::CheckedIn);
});

it('sends nothing, and makes no link, when the guest has no email address', function () {
    Notification::fake();
    ['property' => $property, 'unit' => $unit, 'guest' => $guest] = conciergeSetup();
    $guest->forceFill(['email' => null])->save();
    $before = BookingPreCheckInToken::query()->count();

    app(SendConciergeStayLink::class)(new StayCheckedIn($property->id, $unit->id));

    Notification::assertNothingSent();
    expect(BookingPreCheckInToken::query()->count())->toBe($before);
});

it('sends nothing while the property has the Concierge feature off', function () {
    Notification::fake();
    ['property' => $property, 'unit' => $unit, 'guest' => $guest] = conciergeSetup();
    $guest->forceFill(['email' => 'aisha@example.test'])->save();
    Feature::for($property)->deactivate(Concierge::class);
    $before = BookingPreCheckInToken::query()->count();

    app(SendConciergeStayLink::class)(new StayCheckedIn($property->id, $unit->id));

    Notification::assertNothingSent();
    expect(BookingPreCheckInToken::query()->count())->toBe($before);
});

it('ignores a stay of another property', function () {
    Notification::fake();
    ['property' => $property, 'unit' => $unit, 'guest' => $guest] = conciergeSetup();
    $guest->forceFill(['email' => 'aisha@example.test'])->save();

    app(SendConciergeStayLink::class)(new StayCheckedIn($property->id + 999, $unit->id));

    Notification::assertNothingSent();
});
