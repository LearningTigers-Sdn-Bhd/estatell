<?php

use App\Actions\DoorLocks\DoorLockResult;
use App\Actions\DoorLocks\RegisterDoorCard;
use App\Actions\DoorLocks\RemoveDoorCard;
use App\Enums\PasscodeStatus;
use App\Models\DoorCard;
use App\Models\DoorLock;
use App\Models\Property;
use App\Models\Room;
use App\Services\DoorLocks\CardNumber;
use App\Services\DoorLocks\StayByCard;
use App\Services\DoorLocks\TtlockApi;
use App\Services\DoorLocks\TtlockException;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/DoorLockSetup.php';

function registerCard(DoorLock $lock, string $uid = '0D:D2:5C:E7', string $key = 'card:1', $unit = null): DoorLockResult
{
    return app(RegisterDoorCard::class)($lock, $uid, 'Stay 1', now(), now()->addDays(2), $key, $unit);
}

it('reads the UID bytes as a big-endian decimal', function (string $uid, ?int $number) {
    expect(app(CardNumber::class)->fromUid($uid))->toBe($number);
})->with([
    ['0D:D2:5C:E7', 231890151],
    ['0dd25ce7', 231890151],
    ['0D D2-5C:E7', 231890151],
    ['04A1B2C3D4E5F6', 0x04A1B2C3D4E5F6],
    ['0DD25C', null],
    ['ZZD25CE7', null],
    ['00000000', null],
    ['', null],
]);

it('adds a card with the number, window in milliseconds and gateway add type', function () {
    ['lock' => $lock] = doorLockSetup(gateway: true);
    fakeTtlock();

    app(TtlockApi::class)->addCard('token', 555, 231890151, 'Stay 1', now(), now()->addDay());

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/identityCard/add')
        && $request['lockId'] === 555 && $request['cardNumber'] === '231890151' && $request['addType'] === 2
        && $request['startDate'] === now()->getTimestampMs() && $request['endDate'] === now()->addDay()->getTimestampMs());
});

it('registers a card for a stay and saves the TTLock card id', function () {
    ['property' => $property, 'room' => $room, 'lock' => $lock] = doorLockSetup(gateway: true);
    fakeTtlock();
    $unit = doorLockStay($property, $room);

    $result = registerCard($lock, unit: $unit);

    expect($result->isRefused())->toBeFalse()
        ->and($result->card->status)->toBe(PasscodeStatus::Active)
        ->and($result->card->card_number)->toBe(231890151)
        ->and($result->card->ttlock_card_id)->toBe(7001)
        ->and($result->card->reservation_unit_id)->toBe($unit->id)
        ->and($result->card->property_id)->toBe($property->id);
});

it('registers once for the same key', function () {
    ['lock' => $lock] = doorLockSetup(gateway: true);
    fakeTtlock();

    $first = registerCard($lock);
    $second = registerCard($lock);

    expect($second->card->is($first->card))->toBeTrue()->and(DoorCard::query()->count())->toBe(1)->and(ttlockCalls('identityCard/add'))->toBe(1);
});

it('refuses without a gateway, without a connection, with a bad UID or a past window, and writes nothing', function (string $case) {
    ['lock' => $lock, 'connection' => $connection] = doorLockSetup(gateway: $case !== 'gateway');
    fakeTtlock();

    if ($case === 'connection') {
        $connection->delete();
        $lock->unsetRelation('property');
    }

    $result = $case === 'window'
        ? app(RegisterDoorCard::class)($lock, '0DD25CE7', 'x', now()->subDays(2), now()->subDay(), 'k')
        : registerCard($lock, $case === 'uid' ? '12' : '0DD25CE7');

    expect($result->refusal)->toBe(match ($case) {
        'gateway' => DoorLockResult::REFUSAL_GATEWAY_REQUIRED,
        'connection' => DoorLockResult::REFUSAL_NOT_CONNECTED,
        'uid' => DoorLockResult::REFUSAL_INVALID_CARD,
        'window' => DoorLockResult::REFUSAL_WINDOW_PASSED,
    })->and(DoorCard::query()->count())->toBe(0)->and(ttlockCalls('identityCard'))->toBe(0);
})->with(['gateway', 'connection', 'uid', 'window']);

it('refuses a card that is live on the same lock for another key, but allows it again once revoked', function () {
    ['lock' => $lock] = doorLockSetup(gateway: true);
    fakeTtlock();
    $first = registerCard($lock);

    expect(registerCard($lock, key: 'card:2')->refusal)->toBe(DoorLockResult::REFUSAL_CARD_IN_USE);

    app(RemoveDoorCard::class)($first->card);

    expect(registerCard($lock, key: 'card:2')->isRefused())->toBeFalse();
});

it('saves a TTLock failure on the row as Failed and tries again on repeat', function () {
    ['lock' => $lock] = doorLockSetup(gateway: true);
    Http::fake(['*/v3/identityCard/add' => Http::sequence()->push(['errcode' => -2012, 'errmsg' => 'lock offline'])->push(['cardId' => 7002])]);
    fakeTtlock();

    $failed = registerCard($lock);

    expect($failed->refusal)->toBe(DoorLockResult::REFUSAL_TTLOCK)
        ->and($failed->card->status)->toBe(PasscodeStatus::Failed)
        ->and($failed->card->error)->toContain('lock offline');

    $retry = registerCard($lock);

    expect($retry->card->is($failed->card))->toBeTrue()->and($retry->card->status)->toBe(PasscodeStatus::Active)->and($retry->card->ttlock_card_id)->toBe(7002)->and($retry->card->error)->toBeNull();
});

it('deletes a card through the gateway and marks it Revoked', function () {
    ['lock' => $lock] = doorLockSetup(gateway: true);
    fakeTtlock();
    $card = registerCard($lock)->card;

    $result = app(RemoveDoorCard::class)($card);

    expect($result->isRefused())->toBeFalse()->and($result->warning)->toBeNull()->and($card->refresh()->status)->toBe(PasscodeStatus::Revoked)->and($card->revoked_at)->not->toBeNull();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/identityCard/delete') && $request['cardId'] === 7001 && $request['lockId'] === $lock->ttlock_lock_id && $request['deleteType'] === 2);
});

it('keeps the card as it was when TTLock refuses the delete', function () {
    ['lock' => $lock] = doorLockSetup(gateway: true);
    Http::fake(['*/v3/identityCard/delete' => Http::response(['errcode' => -2012, 'errmsg' => 'lock offline'])]);
    fakeTtlock();
    $card = registerCard($lock)->card;

    $result = app(RemoveDoorCard::class)($card);

    expect($result->refusal)->toBe(DoorLockResult::REFUSAL_TTLOCK)->and($card->refresh()->status)->toBe(PasscodeStatus::Active)->and($card->error)->toContain('lock offline');
});

it('warns when the lock has no gateway and refuses a card that is already gone', function () {
    ['lock' => $lock] = doorLockSetup(gateway: false);
    fakeTtlock();
    $card = DoorCard::factory()->create(['door_lock_id' => $lock->id]);

    $result = app(RemoveDoorCard::class)($card);

    expect($result->warning)->not->toBeNull()->and($card->refresh()->status)->toBe(PasscodeStatus::Revoked)->and(ttlockCalls('identityCard'))->toBe(0)
        ->and(app(RemoveDoorCard::class)($card)->refusal)->toBe(DoorLockResult::REFUSAL_UNCHANGEABLE);
});

it('finds the stay of an active card from its UID, in its own property only', function () {
    ['property' => $property, 'room' => $room, 'lock' => $lock] = doorLockSetup(gateway: true);
    fakeTtlock();
    $unit = doorLockStay($property, $room);
    registerCard($lock, unit: $unit);
    $finder = app(StayByCard::class);

    expect($finder->find($property, '0D:D2:5C:E7')->is($unit))->toBeTrue()
        ->and($finder->find($property, '0DD25CE8'))->toBeNull()
        ->and($finder->find($property, 'nope'))->toBeNull()
        ->and($finder->find(Property::factory()->create(), '0DD25CE7'))->toBeNull();
});

it('finds no stay for a revoked card, a failed card or a card with no stay', function (PasscodeStatus $status, bool $withUnit) {
    ['property' => $property, 'room' => $room, 'lock' => $lock] = doorLockSetup(gateway: true);
    DoorCard::factory()->create([
        'door_lock_id' => $lock->id,
        'card_number' => 231890151,
        'status' => $status,
        'reservation_unit_id' => $withUnit ? doorLockStay($property, $room)->id : null,
    ]);

    expect(app(StayByCard::class)->find($property, '0DD25CE7'))->toBeNull();
})->with([[PasscodeStatus::Revoked, true], [PasscodeStatus::Failed, true], [PasscodeStatus::Pending, true], [PasscodeStatus::Active, false]]);

it('finds the newest stay when a card was used for two stays', function () {
    ['property' => $property, 'room' => $room, 'lock' => $lock] = doorLockSetup(gateway: true);
    $old = doorLockStay($property, $room);
    $new = doorLockStay($property, Room::factory()->create(['property_id' => $property->id, 'number' => '102']));
    DoorCard::factory()->create(['door_lock_id' => $lock->id, 'card_number' => 231890151, 'status' => PasscodeStatus::Revoked, 'reservation_unit_id' => $old->id]);
    DoorCard::factory()->create(['door_lock_id' => $lock->id, 'card_number' => 231890151, 'reservation_unit_id' => $new->id]);

    expect(app(StayByCard::class)->find($property, '0DD25CE7')->is($new))->toBeTrue();
});

it('turns an errcode answer to a card add into a TtlockException', function () {
    Http::fake(['*' => Http::response(['errcode' => -3009, 'errmsg' => 'full'])]);

    app(TtlockApi::class)->addCard('token', 1, 5, 'x', now(), now()->addDay());
})->throws(TtlockException::class);
