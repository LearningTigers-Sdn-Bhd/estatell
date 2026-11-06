<?php

use App\Actions\Kiosk\IssueBookingPreCheckInToken;
use App\Enums\UnitStatus;
use App\Models\BookingPreCheckInToken;
use App\Models\Property;
use App\Models\ReservationUnit;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/KioskSetup.php';

it('issues a per-stay link with only the hash persisted and departure-day expiry in property time', function () {
    ['unit' => $unit, 'property' => $property] = kioskStaySetup();
    $property->settings->forceFill(['time_zone' => 'Asia/Kuala_Lumpur'])->save();

    $link = app(IssueBookingPreCheckInToken::class)($unit);
    $token = basename($link);
    $stored = BookingPreCheckInToken::query()->sole();

    expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/D')
        ->and($stored->token_hash)->toBe(hash('sha256', $token))
        ->and($stored->property_id)->toBe($property->id)
        ->and($stored->reservation_unit_id)->toBe($unit->id)
        ->and($stored->expires_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-16 16:00:00')
        ->and(json_encode($stored->getRawOriginal()))->not->toContain($token)
        ->and(Activity::query()->get()->toJson())->not->toContain($token);

    foreach ([$token, $link, '/c/'.$token] as $value) {
        kioskPost('/bookings/find', ['qr_token' => $value])->assertOk()->assertJsonPath('stay_id', $unit->id);
    }
});

it('finds the precise stay by QR when its reference is ambiguous', function () {
    ['unit' => $first, 'reservation' => $reservation] = kioskStaySetup();
    $second = ReservationUnit::factory()->for($reservation)->create(['arrival_date' => '2026-10-14', 'departure_date' => '2026-10-16']);
    $link = app(IssueBookingPreCheckInToken::class)($second);

    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertStatus(409)->assertJsonPath('error.code', 'server_error')->assertJsonMissingPath('stay_id');
    kioskPost('/bookings/find', ['qr_token' => $link])->assertOk()->assertJsonPath('stay_id', $second->id);
});

it('hides unknown, malformed and other-property QR tokens alike', function () {
    kioskStaySetup();
    ['unit' => $other] = kioskStayFor(Property::factory()->create(), 'OTHER');
    $other->property->settings()->create(['time_zone' => 'Asia/Kuala_Lumpur']);
    $foreign = app(IssueBookingPreCheckInToken::class)($other);

    foreach ([$foreign, str_repeat('a', 43), 'R-100', '/c/short', 'https://host/c/'.str_repeat('a', 43).'?x=1'] as $value) {
        kioskPost('/bookings/find', ['qr_token' => $value])->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
    }
});

it('rejects expired tokens even if the stay itself could still be found', function () {
    ['unit' => $unit] = kioskStaySetup();
    $link = app(IssueBookingPreCheckInToken::class)($unit);
    BookingPreCheckInToken::query()->update(['expires_at' => now()->addMinute()]);
    kioskPost('/bookings/find', ['qr_token' => $link])->assertOk();

    $this->travelTo(now()->addMinute());

    kioskPost('/bookings/find', ['qr_token' => $link])->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertOk();
});

it('applies the same arrival window to QR lookup and never serves cancelled stays', function () {
    ['unit' => $unit] = kioskStaySetup(['arrival_date' => '2026-10-15', 'departure_date' => '2026-10-17']);
    $link = app(IssueBookingPreCheckInToken::class)($unit);
    kioskPost('/bookings/find', ['qr_token' => $link])->assertStatus(409)->assertJsonPath('error.code', 'arrival_not_reached');
    $unit->forceFill(['status' => UnitStatus::Cancelled])->save();

    kioskPost('/bookings/find', ['qr_token' => $link])->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
});

it('finds a PIN guest by QR for departure-day check-out', function () {
    ['unit' => $unit] = kioskStaySetup(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14', 'status' => UnitStatus::CheckedIn, 'checked_in_at' => now()->subDays(2)]);
    $link = app(IssueBookingPreCheckInToken::class)($unit);

    kioskPost('/bookings/find', ['qr_token' => $link])->assertOk()->assertJsonPath('stay_id', $unit->id);
});

it('shares guessing protection between QR and typed references without charging successful scans', function () {
    ['unit' => $unit] = kioskStaySetup();
    $link = app(IssueBookingPreCheckInToken::class)($unit);
    foreach (range(1, 15) as $read) {
        kioskPost('/bookings/find', ['qr_token' => $link])->assertOk();
    }
    foreach (range(1, 10) as $guess) {
        kioskPost('/bookings/find', ['qr_token' => str_repeat('z', 43)])->assertNotFound();
    }

    kioskPost('/bookings/find', ['qr_token' => $link])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');
    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertStatus(429);
});

it('requires the kiosk token and scope for QR lookups', function () {
    ['unit' => $unit, 'kiosk' => $kiosk] = kioskStaySetup();
    $link = app(IssueBookingPreCheckInToken::class)($unit);
    kioskPost('/bookings/find', ['qr_token' => $link], token: null)->assertUnauthorized();
    PersonalAccessToken::query()->update(['abilities' => ['v1:read']]);

    kioskPost('/bookings/find', ['qr_token' => $link])->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
});
