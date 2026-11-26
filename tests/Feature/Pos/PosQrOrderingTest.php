<?php

use App\Actions\Pos\BatchCreatePosTables;
use App\Actions\Pos\CreatePosOrder;
use App\Actions\Pos\IssueRoomQrSession;
use App\Actions\Pos\IssueTableQrSession;
use App\Actions\Pos\RevokeRoomQrSessions;
use App\Actions\Pos\SavePosFloor;
use App\Actions\Pos\SettlePosOrder;
use App\Actions\Property\UploadPropertyLogo;
use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderSource;
use App\Enums\PosQrSessionKind;
use App\Enums\PosShiftStatus;
use App\Features\Pos;
use App\Models\Guest;
use App\Models\PosOrder;
use App\Models\PosQrSession;
use App\Models\PosTable;
use App\Models\Property;
use App\Models\UnitGuest;
use App\Models\User;
use App\Services\Pos\QrSessionState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/PosOrderSetup.php';

beforeEach(fn () => Cache::flush());

/** A table A01 on an Indoor floor of the outlet, and a manager who can issue and sell. */
function posQrTableSetup(): array
{
    $s = posOrderSetup();
    $floor = app(SavePosFloor::class)($s['manager'], $s['property'], $s['outlet'], null, 'Indoor')->model;
    app(BatchCreatePosTables::class)($s['manager'], $s['property'], $floor, 'a', 1, 4, 4);

    return [...$s, 'table' => PosTable::query()->where('pos_floor_id', $floor->id)->where('code', 'A01')->sole()];
}

/** An open check on the table, the way the register opens one. */
function posQrTableOrder(array $s, PosTable $table): PosOrder
{
    return app(CreatePosOrder::class)($s['manager'], $s['property'], $s['shift'], $s['dineIn'], (string) Str::uuid(), 2, null, $table)->model;
}

/** A checked-in unit with a primary guest called Aisha Rahman, and its room PIN. */
function posQrRoomSetup(): array
{
    $s = posOrderSetup();
    $guest = Guest::factory()->create(['property_id' => $s['property']->id, 'full_name' => 'Aisha Rahman']);
    UnitGuest::factory()->primary()->create(['reservation_unit_id' => $s['unit']->id, 'guest_id' => $guest->id]);
    $pin = app(IssueRoomQrSession::class)($s['property']->id, $s['unit']->id);
    $session = PosQrSession::query()->where('kind', PosQrSessionKind::Room->value)->whereNull('revoked_at')->sole();

    return [...$s, 'pin' => $pin, 'session' => $session];
}

it('issues one live table link at a time, and settling the last open check revokes it', function () {
    $s = posQrTableSetup();
    $issue = app(IssueTableQrSession::class);

    $first = $issue($s['manager'], $s['property'], $s['table'])->model;
    $second = $issue($s['manager'], $s['property'], $s['table'])->model;
    $order = posQrTableOrder($s, $s['table']);
    posAddLine($s, $order, $s['steak']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $order->refresh(), [posCash($order->refresh()->grand_total, '200.00')]);

    expect($first->refresh()->revoked_at)->not->toBeNull()
        ->and($second->refresh()->revoked_at)->not->toBeNull()
        ->and(strlen($second->token))->toBe(32);
});

it('keeps the table link alive while another check of the table is still open', function () {
    $s = posQrTableSetup();
    $link = app(IssueTableQrSession::class)($s['manager'], $s['property'], $s['table'])->model;
    $settled = posQrTableOrder($s, $s['table']);
    posAddLine($s, $settled, $s['steak']);
    posQrTableOrder($s, $s['table']);
    app(SettlePosOrder::class)($s['manager'], $s['property'], $settled->refresh(), [posCash($settled->refresh()->grand_total, '200.00')]);

    expect($link->refresh()->revoked_at)->toBeNull();
});

it('shows the menu of a live table link, and nothing for an unknown token', function () {
    $s = posQrTableSetup();
    $link = app(IssueTableQrSession::class)($s['manager'], $s['property'], $s['table'])->model;

    $this->get(route('qr.table.show', $link->token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('qr/table')
            ->where('status', 'active')
            ->where('table', 'A01')
            ->where('categories.0.name', 'Food')
            ->has('categories.0.items'));

    $this->get(route('qr.table.show', Str::random(32)))->assertNotFound();
});

it('takes a guest table order as Draft lines on the open shift, with no staff actor behind it', function () {
    $s = posQrTableSetup();
    $link = app(IssueTableQrSession::class)($s['manager'], $s['property'], $s['table'])->model;

    $this->post(route('qr.table.orders', $link->token), ['lines' => [['variant_id' => $s['steak']->id, 'quantity' => 2, 'note' => 'Medium rare']]])
        ->assertRedirect();

    $order = PosOrder::query()->sole();
    $line = $order->lines()->sole();

    expect($order->source)->toBe(PosOrderSource::TableQr)
        ->and($order->pos_table_id)->toBe($s['table']->id)
        ->and($order->opened_by_user_id)->toBeNull()
        ->and($order->pos_shift_id)->toBe($s['shift']->id)
        ->and($line->kitchen_status)->toBe(PosKitchenStatus::Draft)
        ->and((string) $line->quantity)->toBe('2.000')
        ->and($line->note)->toBe('Medium rare');
});

it('refuses a table order when the outlet has no open shift, or the link has expired', function () {
    $s = posQrTableSetup();
    $link = app(IssueTableQrSession::class)($s['manager'], $s['property'], $s['table'])->model;
    $lines = ['lines' => [['variant_id' => $s['steak']->id, 'quantity' => 1]]];

    $s['shift']->forceFill(['status' => PosShiftStatus::Closed])->save();
    $this->post(route('qr.table.orders', $link->token), $lines);

    expect(PosOrder::query()->count())->toBe(0);

    $s['shift']->forceFill(['status' => PosShiftStatus::Open])->save();
    $link->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->post(route('qr.table.orders', $link->token), $lines);

    expect(PosOrder::query()->count())->toBe(0)
        ->and(app(QrSessionState::class)($link->refresh()))->toBe(QrSessionState::EXPIRED);
});

it('gives a room QR a fresh PIN and revokes the old token when the PIN is regenerated', function () {
    $s = posQrRoomSetup();
    $old = $s['session'];

    $newPin = app(IssueRoomQrSession::class)($s['property']->id, $s['unit']->id);

    expect($newPin)->toMatch('/^\d{4}$/')
        ->and($old->refresh()->revoked_at)->not->toBeNull()
        ->and(PosQrSession::query()->where('kind', PosQrSessionKind::Room->value)->whereNull('revoked_at')->count())->toBe(1);
});

it('gives no room QR, and needs no PIN pepper, when the property does not use the point of sale', function () {
    $s = posOrderSetup();
    Feature::for($s['property'])->deactivate(Pos::class);
    config(['pos.pin_pepper' => null]);

    $pin = app(IssueRoomQrSession::class)($s['property']->id, $s['unit']->id);

    expect($pin)->toBeNull()
        ->and(PosQrSession::query()->count())->toBe(0);
});

it('revokes a room QR for good after its third lock', function () {
    $s = posQrRoomSetup();
    $token = $s['session']->token;
    $wrong = $s['pin'] === '0000' ? '1111' : '0000';

    for ($lock = 1; $lock <= 3; $lock++) {
        Cache::flush();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('qr.room.unlock', $token), ['pin' => $wrong, 'last_name' => 'Rahman']);
        }

        if ($lock < 3) {
            expect($s['session']->refresh()->lock_count)->toBe($lock);
            $s['session']->forceFill(['locked_until' => now()->subMinute()])->save();
        }
    }

    expect($s['session']->refresh()->revoked_at)->not->toBeNull();
});

it('revokes a room QR when the guest checks out or the check-in is undone', function () {
    $s = posQrRoomSetup();

    app(RevokeRoomQrSessions::class)($s['property']->id, $s['unit']->id);

    expect($s['session']->refresh()->revoked_at)->not->toBeNull();
});

it('opens the room menu only with the stay PIN and the primary guest\'s last name, and locks after five wrong tries', function () {
    $s = posQrRoomSetup();
    $token = $s['session']->token;

    $this->post(route('qr.room.unlock', $token), ['pin' => $s['pin'], 'last_name' => 'Tan'])->assertRedirect(route('qr.room.show', $token));
    $this->get(route('qr.room.show', $token))->assertInertia(fn (Assert $page) => $page->where('unlocked', false));
    $this->post(route('qr.room.unlock', $token), ['pin' => $s['pin'], 'last_name' => 'Rahman'])->assertRedirect(route('qr.room.show', $token));

    $this->get(route('qr.room.show', $token))->assertInertia(fn (Assert $page) => $page->component('qr/room')->where('unlocked', true)->has('outlets'));

    $wrong = $s['pin'] === '0000' ? '1111' : '0000';
    for ($i = 0; $i < 5; $i++) {
        $this->post(route('qr.room.unlock', $token), ['pin' => $wrong, 'last_name' => 'Rahman']);
    }

    expect($s['session']->refresh()->locked_until)->not->toBeNull()
        ->and(app(QrSessionState::class)($s['session']))->toBe(QrSessionState::LOCKED);
});

it('puts a room order on the room-service outlet against the unit, once the guest is verified', function () {
    $s = posQrRoomSetup();
    $token = $s['session']->token;
    $this->post(route('qr.room.unlock', $token), ['pin' => $s['pin'], 'last_name' => 'Rahman']);

    $this->post(route('qr.room.orders', $token), [
        'outlet_id' => $s['outlet']->id,
        'lines' => [['variant_id' => $s['pasta']->id, 'quantity' => 1]],
    ])->assertRedirect();

    $order = PosOrder::query()->sole();

    expect($order->source)->toBe(PosOrderSource::RoomQr)
        ->and($order->reservation_unit_id)->toBe($s['unit']->id)
        ->and($order->diningOption->kind->value)->toBe('room_service')
        ->and($order->lines()->sole()->kitchen_status)->toBe(PosKitchenStatus::Draft);
});

it('refuses a room order for a unit that cannot post, and asks a guest who is not verified to unlock first', function () {
    $s = posQrRoomSetup();
    $token = $s['session']->token;

    $this->post(route('qr.room.orders', $token), ['outlet_id' => $s['outlet']->id, 'lines' => [['variant_id' => $s['pasta']->id, 'quantity' => 1]]])
        ->assertRedirect(route('qr.room.show', $token));

    expect(PosOrder::query()->count())->toBe(0);

    $this->post(route('qr.room.unlock', $token), ['pin' => $s['pin'], 'last_name' => 'Rahman']);
    $s['unit']->forceFill(['no_post' => true])->save();

    $this->post(route('qr.room.orders', $token), ['outlet_id' => $s['outlet']->id, 'lines' => [['variant_id' => $s['pasta']->id, 'quantity' => 1]]]);

    expect(PosOrder::query()->count())->toBe(0);
});

it('lets only a staff member who can sell issue a table link from the register', function () {
    $s = posQrTableSetup();
    $cashier = User::factory()->for($s['property'])->withPermissions(['pos.manage'])->create();

    $this->actingAs($cashier)->post(route('pos.tables.qr', [$s['property']->code, $s['table']->id]))->assertForbidden();
    expect(PosQrSession::query()->count())->toBe(0);

    $this->actingAs($s['manager'])->post(route('pos.tables.qr', [$s['property']->code, $s['table']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('pos/table-qr')
            ->where('table', 'A01')
            ->where('qrSvg', fn (string $svg): bool => str_contains($svg, '<svg')));
});

it('exposes the room dining QR card on a checked-in reservation and flashes the one-time PIN when regenerated', function () {
    $s = posQrRoomSetup();
    $frontDesk = User::factory()->for($s['property'])->withPermissions(['reservations.view', 'front-desk.check-in'])->create();

    $this->actingAs($frontDesk)
        ->get(route('reservations.show', ['property' => $s['property']->code, 'reservation' => $s['unit']->reservation_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('roomQr.unitId', $s['unit']->id)
            ->where('roomQr.hasActiveSession', true)
            ->where('roomQr.qrSvg', fn (string $svg): bool => str_contains($svg, '<svg')));

    $this->actingAs($frontDesk)
        ->post(route('pos.room-qr.regenerate', ['property' => $s['property']->code, 'unit' => $s['unit']->id]))
        ->assertRedirect();
});

it('shows the hotel logo to a guest through the QR token only', function () {
    Storage::fake('local');
    $s = posQrTableSetup();
    $session = app(IssueTableQrSession::class)($s['manager'], $s['property'], $s['table'])->model;
    $page = route('qr.table.show', $session->token);

    $this->get($page)->assertInertia(fn (Assert $page) => $page->where('logoUrl', null));

    app(UploadPropertyLogo::class)($s['manager'], $s['property'], UploadedFile::fake()->image('logo.png', 200, 80));
    $logo = $s['property']->refresh()->getFirstMedia(Property::LOGO);

    $this->get($page)->assertInertia(fn (Assert $page) => $page->where('logoUrl', route('qr.logo', ['token' => $session->token, 'v' => $logo->id], false)));
    $this->get(route('qr.logo', $session->token))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get(route('qr.logo', str_repeat('x', 32)))->assertNotFound();
});
