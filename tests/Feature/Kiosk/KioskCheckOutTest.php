<?php

use App\Enums\FolioStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\PasscodeStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Events\StayCheckedOut;
use App\Features\DoorLocks;
use App\Models\BusinessDate;
use App\Models\DoorCard;
use App\Models\DoorLock;
use App\Models\DoorLockConnection;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Kiosk;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/KioskSetup.php';
require_once __DIR__.'/../Support/DoorLockSetup.php';

/** @return array<string, mixed> */
function kioskCheckOutSetup(bool $card = false): array
{
    Http::preventStrayRequests();
    $setup = kioskStaySetup(['status' => UnitStatus::CheckedIn, 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14', 'checked_in_at' => now()->subDays(2)]);
    $property = $setup['property'];
    $room = Room::factory()->create(['property_id' => $property->id, 'room_type_id' => $setup['unit']->room_type_id, 'number' => '101']);
    $roomStatus = RoomStatus::query()->where('room_id', $room->id)->sole();
    foreach (['2026-10-12', '2026-10-13'] as $date) {
        UnitNight::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'date' => $date, 'room_id' => $room->id]);
    }
    $folio = Folio::factory()->for($property)->forUnit($setup['unit'])->create(['reservation_id' => $setup['reservation']->id]);
    $setup = [...$setup, 'room' => $room, 'room_status' => $roomStatus, 'folio' => $folio];
    if ($card) {
        Feature::for($property)->activate(DoorLocks::class);
        $connection = DoorLockConnection::factory()->for($property)->create();
        $lock = DoorLock::factory()->for($property)->create(['room_id' => $room->id, 'has_gateway' => true]);
        $doorCard = DoorCard::factory()->create(['door_lock_id' => $lock->id, 'reservation_unit_id' => $setup['unit']->id, 'card_number' => 231890151]);
        $setup = [...$setup, 'card' => $doorCard, 'lock' => $lock, 'connection' => $connection];
    }

    return $setup;
}

it('finds a departure-day stay by card and shows its folio without a kiosk check-in marker', function () {
    ['unit' => $unit] = kioskCheckOutSetup(card: true);

    kioskPost('/cards/lookup', ['uid' => '0D:D2:5C:E7'])->assertOk()->assertExactJson(['known' => true, 'stay_id' => $unit->id, 'room_number' => '101', 'settled' => true]);
    kioskRead("/stays/{$unit->id}/folio")->assertOk()->assertExactJson(['balance' => ['amount_minor' => 0, 'currency' => 'MYR'], 'settled' => true]);
    Http::assertNothingSent();
});

it('treats unknown, invalid and other-property cards alike', function () {
    ['card' => $card] = kioskCheckOutSetup(card: true);
    $card->forceFill(['property_id' => Property::factory()->create()->id])->save();

    foreach (['FFFFFFFF', 'not-a-uid', '0DD25CE7'] as $uid) {
        kioskPost('/cards/lookup', ['uid' => $uid])->assertOk()->assertExactJson(['known' => false]);
    }
    Http::assertNothingSent();
});

it('does not look up an inactive card or a stay that is no longer in house', function (string $state) {
    ['unit' => $unit, 'card' => $card] = kioskCheckOutSetup(card: true);
    if ($state === 'checked_out') {
        $unit->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
    } else {
        $card->forceFill(['status' => $state])->save();
    }

    kioskPost('/cards/lookup', ['uid' => '0DD25CE7'])->assertExactJson(['known' => false]);
})->with(['pending', 'failed', 'revoked', 'expired', 'checked_out']);

it('checks out a settled PIN guest, closes the folio and marks the room dirty without a staff actor', function () {
    ['unit' => $unit, 'folio' => $folio, 'room_status' => $roomStatus, 'property' => $property] = kioskCheckOutSetup();
    Event::fake([StayCheckedOut::class]);

    kioskPost("/stays/{$unit->id}/check-out")->assertOk()->assertExactJson(['checked_out' => true, 'invoice_sent' => false]);

    expect($unit->refresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and($unit->checked_out_at)->not->toBeNull()
        ->and($folio->refresh()->status)->toBe(FolioStatus::Closed)->and($folio->closed_by)->toBeNull()
        ->and($folio->invoice_number)->not->toBeNull()
        ->and($roomStatus->refresh()->status)->toBe(HousekeepingStatus::Dirty)->and($roomStatus->last_changed_by)->toBeNull()
        ->and(Activity::query()->where('event', 'reservation.unit_checked_out')->sole()->causer_id)->toBeNull();
    Event::assertDispatched(StayCheckedOut::class, fn ($event) => $event->propertyId === $property->id && $event->reservationUnitId === $unit->id);
    Http::assertNothingSent();
});

it('removes cards at check-out and replays the first answer', function () {
    ['unit' => $unit, 'card' => $card] = kioskCheckOutSetup(card: true);
    fakeTtlock();
    $headers = ['Idempotency-Key' => 'checkout-1'];

    $first = kioskPost("/stays/{$unit->id}/check-out", ['uid' => '0DD25CE7'], headers: $headers)->assertOk();
    kioskPost("/stays/{$unit->id}/check-out", ['uid' => 'FFFFFFFF'], headers: $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertExactJson($first->json());
    kioskPost("/stays/{$unit->id}/check-out", ['uid' => '0DD25CE7'])->assertOk();

    expect($card->refresh()->status)->toBe(PasscodeStatus::Revoked)
        ->and(ttlockCalls('/v3/identityCard/delete'))->toBe(1)
        ->and(Activity::query()->where('event', 'reservation.unit_checked_out')->count())->toBe(1);
});

it('refuses both charges and credits until the folio is exactly zero', function (string $amount, int $minor) {
    ['unit' => $unit, 'folio' => $folio] = kioskCheckOutSetup();
    FolioLine::factory()->for($folio)->create(['amount' => $amount, 'unit_amount' => $amount, 'posting_date' => '2026-10-14']);

    kioskRead("/stays/{$unit->id}/folio")->assertExactJson(['balance' => ['amount_minor' => $minor, 'currency' => 'MYR'], 'settled' => false]);
    kioskPost("/stays/{$unit->id}/check-out")->assertStatus(409)->assertJsonPath('error.code', 'balance_not_settled');
    expect($unit->refresh()->status)->toBe(UnitStatus::CheckedIn)->and($folio->refresh()->status)->toBe(FolioStatus::Open)
        ->and(Activity::query()->where('event', 'kiosk.checked_out')->exists())->toBeFalse();
})->with([['12.34', 1234], ['-12.34', -1234]]);

it('does not net an unsettled folio against credit on another folio', function () {
    ['unit' => $unit, 'folio' => $folio, 'property' => $property] = kioskCheckOutSetup();
    $other = Folio::factory()->for($property)->forUnit($unit)->create(['reservation_id' => $unit->reservation_id]);
    FolioLine::factory()->for($folio)->create(['amount' => '10.00', 'unit_amount' => '10.00']);
    FolioLine::factory()->for($other)->payment('10.00')->create();

    kioskRead("/stays/{$unit->id}/folio")->assertJsonPath('balance.amount_minor', 0)->assertJsonPath('settled', false);
    kioskPost("/stays/{$unit->id}/check-out")->assertStatus(409)->assertJsonPath('error.code', 'balance_not_settled');
});

it('does not remove a card when the balance is not settled', function () {
    ['unit' => $unit, 'folio' => $folio, 'card' => $card] = kioskCheckOutSetup(card: true);
    FolioLine::factory()->for($folio)->create();

    kioskPost("/stays/{$unit->id}/check-out", ['uid' => '0DD25CE7'])->assertStatus(409)->assertJsonPath('error.code', 'balance_not_settled');
    expect($card->refresh()->status)->toBe(PasscodeStatus::Active);
    Http::assertNothingSent();
});

it('rejects a malformed or unrelated card without changing the stay', function (array $body) {
    ['unit' => $unit] = kioskCheckOutSetup();

    kioskPost("/stays/{$unit->id}/check-out", $body)->assertNotFound()->assertJsonPath('error.code', 'card_unknown');
    expect($unit->refresh()->status)->toBe(UnitStatus::CheckedIn);
})->with([[['uid' => []]], [['uid' => 'not-a-uid']], [['uid' => 'FFFFFFFF']]]);

it('fails closed on card deletion failures and resumes cleanup without repeating check-out', function (string $failure) {
    ['unit' => $unit, 'card' => $card] = kioskCheckOutSetup(card: true);
    $attempts = 0;
    Http::fake(['*/v3/identityCard/delete' => function () use ($failure, &$attempts) {
        if (++$attempts > 1) {
            return Http::response(['errcode' => 0]);
        }

        return match ($failure) {
            'timeout' => throw new ConnectionException('timeout'),
            '500' => Http::response([], 500),
            'empty' => Http::response([]),
            'refused' => Http::response(['errcode' => -3009]),
        };
    }]);
    $headers = ['Idempotency-Key' => 'retry-checkout'];

    kioskPost("/stays/{$unit->id}/check-out", ['uid' => '0DD25CE7'], headers: $headers)->assertStatus(503)->assertJsonPath('error.code', 'lock_unavailable');
    expect($card->refresh()->status)->toBe(PasscodeStatus::Active)->and($unit->refresh()->status)->toBe(UnitStatus::CheckedOut);
    kioskPost("/stays/{$unit->id}/check-out", ['uid' => '0DD25CE7'], headers: $headers)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
    expect($card->refresh()->status)->toBe(PasscodeStatus::Revoked)
        ->and(Activity::query()->where('event', 'reservation.unit_checked_out')->count())->toBe(1);
})->with(['timeout', '500', 'refused', 'empty']);

it('keeps successful removals when another card fails and retries only the remaining card', function () {
    ['unit' => $unit, 'card' => $card, 'lock' => $lock] = kioskCheckOutSetup(card: true);
    $second = DoorCard::factory()->create(['door_lock_id' => $lock->id, 'reservation_unit_id' => $unit->id]);
    $attempts = 0;
    Http::fake(['*/v3/identityCard/delete' => function () use (&$attempts) {
        return Http::response(['errcode' => ++$attempts === 2 ? -3009 : 0]);
    }]);

    kioskPost("/stays/{$unit->id}/check-out", ['uid' => '0DD25CE7'])->assertStatus(503);
    expect($card->refresh()->status)->toBe(PasscodeStatus::Revoked)->and($second->refresh()->status)->toBe(PasscodeStatus::Active);
    kioskPost("/stays/{$unit->id}/check-out", ['uid' => '0DD25CE7'])->assertOk();
    expect($second->refresh()->status)->toBe(PasscodeStatus::Revoked)->and($attempts)->toBe(3);
});

it('fails closed when a card cannot be removed through an enabled gateway', function (string $reason) {
    ['unit' => $unit, 'card' => $card, 'lock' => $lock, 'connection' => $connection] = kioskCheckOutSetup(card: true);
    if ($reason === 'gateway') {
        $lock->forceFill(['has_gateway' => false])->save();
    } else {
        $connection->forceFill(['enabled' => false])->save();
    }

    kioskPost("/stays/{$unit->id}/check-out", ['uid' => '0DD25CE7'])->assertStatus(503)->assertJsonPath('error.code', 'lock_unavailable');
    expect($card->refresh()->status)->toBe(PasscodeStatus::Active);
    Http::assertNothingSent();
})->with(['gateway', 'connection']);

it('hides other-property and missing stays identically', function () {
    kioskCheckOutSetup();
    ['unit' => $foreign] = kioskStayFor(Property::factory()->create(), 'R-200', ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()]);

    foreach ([$foreign->id, 999999, 'abc'] as $id) {
        kioskRead("/stays/{$id}/folio")->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
        kioskPost("/stays/{$id}/check-out")->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
    }
});

it('only opens the check-out window at departure and keeps check-in keys inaccessible', function () {
    ['unit' => $unit] = kioskCheckOutSetup(card: true);
    $unit->forceFill(['departure_date' => '2026-10-15'])->save();
    kioskRead("/stays/{$unit->id}/folio")->assertNotFound();
    kioskPost("/stays/{$unit->id}/check-out")->assertNotFound();
    kioskPost('/cards/lookup', ['uid' => '0DD25CE7'])->assertExactJson(['known' => false]);
    $unit->forceFill(['departure_date' => '2026-10-14'])->save();

    kioskRead("/stays/{$unit->id}/folio")->assertOk();
    kioskRead("/stays/{$unit->id}/key")->assertNotFound();
    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertOk()->assertJsonPath('stay_id', $unit->id);
});

it('does not allow check-out without an open business date', function () {
    ['unit' => $unit, 'property' => $property] = kioskCheckOutSetup();
    BusinessDate::query()->where('property_id', $property->id)->delete();

    kioskRead("/stays/{$unit->id}/folio")->assertNotFound();
    kioskPost("/stays/{$unit->id}/check-out")->assertNotFound();
});

it('restricts completed check-out retries to the same kiosk for thirty minutes', function () {
    ['unit' => $unit, 'property' => $property] = kioskCheckOutSetup();
    Kiosk::factory()->for($property)->withToken('est_live_secondkioskaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->create();
    kioskPost("/stays/{$unit->id}/check-out")->assertOk();
    kioskPost("/stays/{$unit->id}/check-out", token: 'est_live_secondkioskaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->assertNotFound();
    kioskRead("/stays/{$unit->id}/folio")->assertNotFound();

    $this->travelTo(now()->addMinutes(31));
    kioskPost("/stays/{$unit->id}/check-out")->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
});

it('requires kiosk authentication and scope on all check-out routes', function () {
    ['unit' => $unit, 'kiosk' => $kiosk] = kioskCheckOutSetup();
    foreach ([null, KIOSK_TEST_TOKEN] as $token) {
        if ($token !== null) {
            PersonalAccessToken::query()->update(['abilities' => ['v1:read']]);
        }
        kioskPost('/cards/lookup', ['uid' => '0DD25CE7'], token: $token)->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
        kioskRead("/stays/{$unit->id}/folio", token: $token)->assertUnauthorized();
        kioskPost("/stays/{$unit->id}/check-out", token: $token)->assertUnauthorized();
    }
});

it('locks every check-out lookup after repeated card guesses and refunds successful reads', function () {
    ['unit' => $unit] = kioskCheckOutSetup(card: true);
    foreach (range(1, 15) as $read) {
        kioskPost('/cards/lookup', ['uid' => '0DD25CE7'])->assertJsonPath('known', true);
    }
    foreach (range(1, 10) as $guess) {
        kioskPost('/cards/lookup', ['uid' => 'FFFFFFFF'])->assertExactJson(['known' => false]);
    }

    kioskPost('/cards/lookup', ['uid' => '0DD25CE7'])->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error.code', 'rate_limited');
    kioskRead("/stays/{$unit->id}/folio")->assertStatus(429);
    kioskPost("/stays/{$unit->id}/check-out")->assertStatus(429);
});

it('still rejects a closing staff actor without a closure timestamp', function () {
    ['folio' => $folio, 'property' => $property] = kioskCheckOutSetup();
    $actor = User::factory()->for($property)->create();

    expect(fn () => DB::transaction(fn () => $folio->forceFill(['closed_by' => $actor->id])->save()))
        ->toThrow(QueryException::class);
    expect($folio->fresh()->closed_by)->toBeNull();
});

it('uses the departure stay day while audit is pending and rolls back refused night postings', function (bool $hasRoomCode, string $error) {
    ['unit' => $unit, 'folio' => $folio, 'property' => $property, 'room' => $room] = kioskCheckOutSetup();
    $unit->forceFill(['departure_date' => '2026-10-15'])->save();
    UnitNight::factory()->create([
        'reservation_unit_id' => $unit->id, 'date' => '2026-10-14', 'room_id' => $room->id,
        'rate_plan_id' => $unit->rate_plan_id, 'base_amount' => '100.00', 'amount' => '100.00',
        'extra_amount' => '0.00', 'supplement_amount' => '0.00', 'discount_amount' => '0.00',
    ]);
    if ($hasRoomCode) {
        TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    }
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(1, 0));

    kioskRead("/stays/{$unit->id}/folio")->assertOk();
    kioskPost("/stays/{$unit->id}/check-out")->assertStatus(409)->assertJsonPath('error.code', $error);
    expect($unit->refresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($folio->refresh()->status)->toBe(FolioStatus::Open)
        ->and(FolioLine::query()->where('folio_id', $folio->id)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'kiosk.checked_out')->exists())->toBeFalse();
    Http::assertNothingSent();
})->with([[true, 'balance_not_settled'], [false, 'server_error']]);
