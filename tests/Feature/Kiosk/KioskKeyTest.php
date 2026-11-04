<?php

use App\Enums\PasscodeStatus;
use App\Features\DoorLocks;
use App\Models\DoorCard;
use App\Models\DoorLock;
use App\Models\DoorPasscode;
use App\Models\Property;
use App\Models\Room;
use App\Models\UnitNight;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/KioskKeySetup.php';

it('reads an active PIN without caching it or logging it', function () {
    ['unit' => $unit, 'lock' => $lock] = kioskKeySetup();
    DoorPasscode::factory()->create(['door_lock_id' => $lock->id, 'reservation_unit_id' => $unit->id, 'code' => '482913', 'ends_at' => now()->addDay()]);

    kioskRead("/stays/{$unit->id}/key")->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['pin' => ['status' => 'ready', 'code' => '482913', 'valid_from' => now()->toRfc3339String(), 'valid_until' => now()->addDay()->toRfc3339String()]]);
    expect(Activity::query()->get()->toJson())->not->toContain('482913');
    Http::assertNothingSent();
});

it('reports each queued PIN state without exposing a code', function (PasscodeStatus $status, string $expected) {
    ['unit' => $unit, 'lock' => $lock] = kioskKeySetup();
    DoorPasscode::factory()->create(['door_lock_id' => $lock->id, 'reservation_unit_id' => $unit->id, 'status' => $status]);

    kioskRead("/stays/{$unit->id}/key")->assertOk()->assertExactJson(['pin' => ['status' => $expected]]);
})->with([[PasscodeStatus::Pending, 'pending'], [PasscodeStatus::Failed, 'failed']]);

it('waits at most 120 seconds for a PIN job to create its row', function () {
    ['unit' => $unit] = kioskKeySetup();
    kioskRead("/stays/{$unit->id}/key")->assertExactJson(['pin' => ['status' => 'pending']]);

    $this->travelTo(now()->addSeconds(121));

    kioskRead("/stays/{$unit->id}/key")->assertExactJson(['pin' => ['status' => 'failed']]);
});

it('uses the newest nonexpired PIN and requires every lock to be ready', function () {
    ['unit' => $unit, 'lock' => $lock, 'property' => $property] = kioskKeySetup();
    DoorPasscode::factory()->create(['door_lock_id' => $lock->id, 'reservation_unit_id' => $unit->id, 'code' => '111111']);
    DoorPasscode::factory()->create(['door_lock_id' => $lock->id, 'reservation_unit_id' => $unit->id, 'code' => '222222']);
    DoorPasscode::factory()->create(['door_lock_id' => $lock->id, 'reservation_unit_id' => $unit->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->subSecond()]);
    kioskRead("/stays/{$unit->id}/key")->assertJsonPath('pin.code', '222222');
    $room = Room::factory()->create(['property_id' => $property->id]);
    $second = DoorLock::factory()->for($property)->create(['room_id' => $room->id, 'has_gateway' => true]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->whereDate('date', '2026-10-15')->update(['room_id' => $room->id]);
    kioskRead("/stays/{$unit->id}/key")->assertExactJson(['pin' => ['status' => 'pending']]);
    DoorPasscode::factory()->create(['door_lock_id' => $second->id, 'reservation_unit_id' => $unit->id, 'status' => PasscodeStatus::Failed]);
    kioskRead("/stays/{$unit->id}/key")->assertExactJson(['pin' => ['status' => 'failed']]);
});

it('registers a card once even with a new request key or UID spelling', function () {
    ['unit' => $unit, 'lock' => $lock] = kioskKeySetup();
    fakeTtlock();
    $headers = ['Idempotency-Key' => 'card-1'];
    $first = kioskPost("/stays/{$unit->id}/cards", ['uid' => '0D:D2:5C:E7'], headers: $headers)->assertOk()->assertJsonPath('registered', true);
    kioskPost("/stays/{$unit->id}/cards", ['uid' => 'FFFFFFFF'], headers: $headers)
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertExactJson($first->json());
    kioskPost("/stays/{$unit->id}/cards", ['uid' => '0dd25ce7'], headers: ['Idempotency-Key' => 'card-2'])->assertOk();

    $card = DoorCard::query()->sole();
    expect($card->status)->toBe(PasscodeStatus::Active)
        ->and($card->reservation_unit_id)->toBe($unit->id)->and($card->card_number)->toBe(231890151)
        ->and(ttlockCalls('/v3/identityCard/add'))->toBe(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/identityCard/add') && $request['lockId'] === $lock->ttlock_lock_id && $request['addType'] === 2);
});

it('requires registration on every room lock and reuses partial success on retry', function () {
    ['unit' => $unit, 'property' => $property, 'lock' => $firstLock] = kioskKeySetup();
    $room = Room::factory()->create(['property_id' => $property->id]);
    $secondLock = DoorLock::factory()->for($property)->create(['room_id' => $room->id, 'has_gateway' => true]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->whereDate('date', '2026-10-15')->update(['room_id' => $room->id]);
    $attempts = 0;
    Http::fake(['*/v3/identityCard/add' => function ($request) use ($secondLock, &$attempts) {
        if ($request['lockId'] === $secondLock->ttlock_lock_id && ++$attempts === 1) {
            return Http::response(['errcode' => -3009]);
        }

        return Http::response(['cardId' => 7001]);
    }]);

    kioskPost("/stays/{$unit->id}/cards", ['uid' => '0DD25CE7'])->assertStatus(503)->assertJsonPath('error.code', 'lock_unavailable');
    expect(DoorCard::query()->where('door_lock_id', $firstLock->id)->sole()->status)->toBe(PasscodeStatus::Active)
        ->and(DoorCard::query()->where('door_lock_id', $secondLock->id)->sole()->status)->toBe(PasscodeStatus::Failed);
    kioskPost("/stays/{$unit->id}/cards", ['uid' => '0DD25CE7'])->assertOk()->assertJsonPath('registered', true);
    expect(DoorCard::query()->where('status', PasscodeStatus::Active)->count())->toBe(2)
        ->and(ttlockCalls('/v3/identityCard/add'))->toBe(3);
});

it('answers card_unknown for a malformed UID and saves nothing', function (array $body) {
    ['unit' => $unit] = kioskKeySetup();

    kioskPost("/stays/{$unit->id}/cards", $body)->assertNotFound()->assertJsonPath('error.code', 'card_unknown');
    expect(DoorCard::query()->exists())->toBeFalse();
    Http::assertNothingSent();
})->with([[[]], [['uid' => []]], [['uid' => 'not-a-card']]]);

it('fails closed without a usable lock connection', function (string $reason) {
    ['unit' => $unit, 'lock' => $lock, 'connection' => $connection, 'property' => $property] = kioskKeySetup();
    match ($reason) {
        'no lock' => $lock->update(['room_id' => null]),
        'no gateway' => $lock->update(['has_gateway' => false]),
        'disabled' => $connection->update(['enabled' => false]),
        'disconnected' => $connection->delete(),
        'feature off' => Feature::for($property)->deactivate(DoorLocks::class),
    };

    kioskPost("/stays/{$unit->id}/cards", ['uid' => '0DD25CE7'])->assertStatus(503)->assertJsonPath('error.code', 'lock_unavailable');
    if ($reason !== 'no gateway') {
        kioskRead("/stays/{$unit->id}/key")->assertExactJson(['pin' => ['status' => 'failed']]);
    }
    expect(DoorCard::query()->exists())->toBeFalse();
    Http::assertNothingSent();
})->with(['no lock', 'no gateway', 'disabled', 'disconnected', 'feature off']);

it('fails closed on TTLock failures and retries Failed rows without caching 503', function (string $failure) {
    ['unit' => $unit] = kioskKeySetup();
    $attempts = 0;
    Http::fake(['*/v3/identityCard/add' => function () use ($failure, &$attempts) {
        if (++$attempts > 1) {
            return Http::response(['cardId' => 7001]);
        }

        return match ($failure) {
            '500' => Http::response(['cardId' => 7001], 500),
            'refused' => Http::response(['errcode' => -3009, 'errmsg' => 'full']),
            'empty' => Http::response([]),
            'timeout' => throw new ConnectionException('timeout'),
        };
    }]);
    $headers = ['Idempotency-Key' => 'retry-card'];

    kioskPost("/stays/{$unit->id}/cards", ['uid' => '0DD25CE7'], headers: $headers)->assertStatus(503)->assertJsonPath('error.code', 'lock_unavailable');
    expect(DoorCard::query()->sole()->status)->toBe(PasscodeStatus::Failed);
    kioskPost("/stays/{$unit->id}/cards", ['uid' => '0DD25CE7'], headers: $headers)->assertOk()->assertHeaderMissing('Idempotent-Replayed')->assertJsonPath('registered', true);
    expect(DoorCard::query()->sole()->status)->toBe(PasscodeStatus::Active);
})->with(['500', 'refused', 'empty', 'timeout']);

it('refuses an in-use or pending card and a fifth card', function (string $reason) {
    ['unit' => $unit, 'lock' => $lock] = kioskKeySetup();
    if ($reason === 'limit') {
        DoorCard::factory()->count(4)->create(['door_lock_id' => $lock->id, 'reservation_unit_id' => $unit->id]);
    } else {
        DoorCard::factory()->create(['door_lock_id' => $lock->id, 'reservation_unit_id' => $unit->id,
            'card_number' => 231890151, 'status' => PasscodeStatus::Pending,
            'idempotency_key' => $reason === 'pending' ? "stay:{$unit->id}:{$lock->id}:card:231890151" : 'another-stay']);
    }

    kioskPost("/stays/{$unit->id}/cards", ['uid' => '0DD25CE7'])->assertStatus(503)->assertJsonPath('error.code', 'lock_unavailable');
    Http::assertNothingSent();
})->with(['in use', 'pending', 'limit']);

it('does not register a card while another registration holds the stay lock', function () {
    ['unit' => $unit] = kioskKeySetup();
    $lock = Cache::lock('kiosk-register-card:'.$unit->id, 120);
    $lock->get();
    try {
        kioskPost("/stays/{$unit->id}/cards", ['uid' => '0DD25CE7'])->assertStatus(503)->assertJsonPath('error.code', 'lock_unavailable');
        expect(DoorCard::query()->exists())->toBeFalse();
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

it('hides keys for another property, front-desk check-in or expired kiosk window', function (string $reason) {
    ['unit' => $unit] = kioskKeySetup();
    if ($reason === 'property') {
        $unit = kioskStayFor(Property::factory()->create(), 'R-200')['unit'];
    } elseif ($reason === 'front desk') {
        Activity::query()->where('event', 'kiosk.checked_in')->delete();
    } else {
        $this->travelTo(now()->addMinutes(31));
    }

    kioskRead("/stays/{$unit->id}/key")->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
    kioskPost("/stays/{$unit->id}/cards", ['uid' => '0DD25CE7'])->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
    Http::assertNothingSent();
})->with(['property', 'front desk', 'expired']);

it('requires a kiosk token for keys and cards', function () {
    ['unit' => $unit, 'kiosk' => $kiosk] = kioskKeySetup();
    kioskRead("/stays/{$unit->id}/key", token: null)->assertUnauthorized();
    kioskPost("/stays/{$unit->id}/cards", ['uid' => '0DD25CE7'], token: null)->assertUnauthorized();
    $kiosk->update(['scopes' => 'v1:read']);
    kioskRead("/stays/{$unit->id}/key")->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
    kioskPost("/stays/{$unit->id}/cards", ['uid' => '0DD25CE7'])->assertUnauthorized();
    Http::assertNothingSent();
});

it('applies the shared lookup lockout to keys and cards', function () {
    ['unit' => $unit] = kioskKeySetup();
    foreach (range(1, 10) as $miss) {
        kioskRead('/stays/999999/key')->assertNotFound();
    }

    kioskRead("/stays/{$unit->id}/key")->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    kioskPost("/stays/{$unit->id}/cards", ['uid' => '0DD25CE7'])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    Http::assertNothingSent();
});
