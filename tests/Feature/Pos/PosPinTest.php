<?php

use App\Actions\Pos\RevokePosStaffPin;
use App\Actions\Pos\SetPosStaffPin;
use App\Actions\Pos\VerifyPosApproval;
use App\Actions\Pos\VerifyPosPin;
use App\Enums\PermissionEnum;
use App\Enums\PosPinOutcome;
use App\Features\NativePos;
use App\Models\PosStaffPin;
use App\Models\PosTerminalDevice;
use App\Models\User;
use App\Services\Pos\PosPinHasher;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../Support/NativePosSetup.php';

beforeEach(fn () => Cache::flush());

it('stores a PIN as a peppered hash, never the digits', function () {
    $setup = nativePosSetup();
    $user = User::factory()->for($setup['property'])->withPermissions(['pos.sell'])->create();

    expect(app(SetPosStaffPin::class)($user, '4821'))->toBeNull();

    $pin = PosStaffPin::query()->where('user_id', $user->id)->sole();
    expect($pin->pin_hash)->not->toContain('4821')
        ->and($pin->pin_length)->toBe(4)
        ->and(app(PosPinHasher::class)->check('4821', $pin->pin_hash))->toBeTrue()
        ->and(Hash::check('4821', $pin->pin_hash))->toBeFalse();

    config(['pos.pin_pepper' => 'other-pepper']);
    expect(app(PosPinHasher::class)->check('4821', $pin->pin_hash))->toBeFalse();
});

it('refuses to hash without a pepper', function () {
    config(['pos.pin_pepper' => null]);

    app(PosPinHasher::class)->make('1234');
})->throws(RuntimeException::class);

it('replaces the PIN when a user sets it again', function () {
    $setup = nativePosSetup();

    expect(app(SetPosStaffPin::class)($setup['cashier'], '5678'))->toBeNull();

    expect(PosStaffPin::query()->where('user_id', $setup['cashier']->id)->count())->toBe(1);
    expect(($this->verify ?? app(VerifyPosPin::class))($setup['property'], $setup['device'], $setup['cashier'], '5678')->isVerified())->toBeTrue();
});

it('enforces 4 to 6 digits, and 6 for elevated roles', function (string $pin, string $role, ?string $refusal) {
    $setup = nativePosSetup();
    $user = $setup[$role];

    expect(app(SetPosStaffPin::class)($user, $pin))->toBe($refusal);
})->with([
    'cashier 4 digits' => ['1234', 'cashier', null],
    'cashier 6 digits' => ['123456', 'cashier', null],
    'cashier 3 digits' => ['123', 'cashier', 'pin_invalid'],
    'cashier 7 digits' => ['1234567', 'cashier', 'pin_invalid'],
    'cashier letters' => ['12ab', 'cashier', 'pin_invalid'],
    'manager 5 digits' => ['12345', 'manager', 'pin_too_short_for_role'],
    'manager 6 digits' => ['654321', 'manager', null],
]);

it('refuses to set a PIN while Native POS is off', function () {
    $setup = nativePosSetup();
    Feature::for($setup['property'])->deactivate(NativePos::class);

    expect(app(SetPosStaffPin::class)($setup['cashier'], '9999'))->toBe('native_pos_disabled');
});

it('lets a manager revoke a PIN but only inside their own property', function () {
    $setup = nativePosSetup();
    $other = nativePosSetup();

    expect(app(RevokePosStaffPin::class)($setup['manager'], $setup['property'], $setup['cashier']))->toBeTrue();
    expect(PosStaffPin::query()->where('user_id', $setup['cashier']->id)->exists())->toBeFalse();
    expect(app(RevokePosStaffPin::class)($setup['manager'], $setup['property'], $setup['cashier']))->toBeFalse();
    expect(app(RevokePosStaffPin::class)($setup['manager'], $setup['property'], $other['cashier']))->toBeFalse();
    expect(PosStaffPin::query()->where('user_id', $other['cashier']->id)->exists())->toBeTrue();
});

it('verifies a right PIN and refuses a wrong one, an unknown user and a user with no PIN', function () {
    $setup = nativePosSetup();
    $verify = app(VerifyPosPin::class);
    $nobody = User::factory()->for($setup['property'])->create();
    $stranger = User::factory()->create();

    expect($verify($setup['property'], $setup['device'], $setup['cashier'], '1234')->isVerified())->toBeTrue()
        ->and($verify($setup['property'], $setup['device'], $setup['cashier'], '9999')->outcome)->toBe(PosPinOutcome::InvalidPin)
        ->and($verify($setup['property'], $setup['device'], $nobody, '1234')->outcome)->toBe(PosPinOutcome::NoPin)
        ->and($verify($setup['property'], $setup['device'], $stranger, '1234')->outcome)->toBe(PosPinOutcome::InvalidPin);
});

it('refuses an inactive PIN and a deactivated user', function () {
    $setup = nativePosSetup();
    $verify = app(VerifyPosPin::class);

    PosStaffPin::query()->where('user_id', $setup['cashier']->id)->update(['active' => false]);
    expect($verify($setup['property'], $setup['device'], $setup['cashier'], '1234')->outcome)->toBe(PosPinOutcome::NoPin);

    $setup['manager']->forceFill(['deactivated_at' => now()])->save();
    expect($verify($setup['property'], $setup['device'], $setup['manager'], '123456')->outcome)->toBe(PosPinOutcome::InvalidPin);
});

it('refuses a revoked device and another property device', function () {
    $setup = nativePosSetup();
    $other = nativePosSetup();
    $verify = app(VerifyPosPin::class);

    expect($verify($setup['property'], $other['device'], $setup['cashier'], '1234')->outcome)->toBe(PosPinOutcome::InvalidPin);

    $setup['device']->forceFill(['revoked_at' => now()])->save();
    expect($verify($setup['property'], $setup['device'], $setup['cashier'], '1234')->outcome)->toBe(PosPinOutcome::InvalidPin);
});

it('locks a user on one device after 5 wrong PINs without locking another device', function () {
    $setup = nativePosSetup();
    $second = PosTerminalDevice::factory()->create(['property_id' => $setup['property']->id, 'pos_outlet_id' => $setup['outlet']->id]);
    $verify = app(VerifyPosPin::class);

    foreach (range(1, 4) as $_) {
        expect($verify($setup['property'], $setup['device'], $setup['cashier'], '0000')->outcome)->toBe(PosPinOutcome::InvalidPin);
    }

    expect($verify($setup['property'], $setup['device'], $setup['cashier'], '0000')->outcome)->toBe(PosPinOutcome::Locked)
        ->and($verify($setup['property'], $setup['device'], $setup['cashier'], '1234')->outcome)->toBe(PosPinOutcome::Locked)
        ->and($verify($setup['property'], $second, $setup['cashier'], '1234')->isVerified())->toBeTrue()
        ->and($verify($setup['property'], $setup['device'], $setup['manager'], '123456')->isVerified())->toBeTrue();

    $this->travel(16)->minutes();
    expect($verify($setup['property'], $setup['device'], $setup['cashier'], '1234')->isVerified())->toBeTrue();
});

it('locks a user on every device after 12 wrong PINs and logs it without the guesses', function () {
    $setup = nativePosSetup();
    $devices = PosTerminalDevice::factory()->count(3)->create(['property_id' => $setup['property']->id, 'pos_outlet_id' => $setup['outlet']->id]);
    $verify = app(VerifyPosPin::class);

    foreach (range(0, 11) as $i) {
        $verify($setup['property'], $devices[$i % 3], $setup['cashier'], '000'.$i);
    }

    expect($verify($setup['property'], $setup['device'], $setup['cashier'], '1234')->outcome)->toBe(PosPinOutcome::Locked);

    $log = Activity::query()->where('event', 'pos.pin_locked')->where('properties->scope', 'user')->sole();
    expect($log->properties['user_id'])->toBe($setup['cashier']->id)
        ->and(json_encode($log->properties))->not->toContain('0001');

    $this->travel(31)->minutes();
    expect($verify($setup['property'], $setup['device'], $setup['cashier'], '1234')->isVerified())->toBeTrue();
});

it('refuses a correct PIN of a user who gained an elevated permission with a short PIN', function () {
    $setup = nativePosSetup();
    $verify = app(VerifyPosPin::class);

    expect($verify($setup['property'], $setup['device'], $setup['cashier'], '1234')->isVerified())->toBeTrue();

    setPermissionsTeamId($setup['property']->id);
    $role = $setup['cashier']->roles()->sole();
    $role->givePermissionTo(PermissionEnum::PosVoid->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($verify($setup['property'], $setup['device'], $setup['cashier']->fresh(), '1234')->outcome)->toBe(PosPinOutcome::PinUpgradeRequired);
});

it('accepts a Manager PIN override only from a manager with the permission', function () {
    $setup = nativePosSetup();
    $approve = app(VerifyPosApproval::class);

    $ok = $approve($setup['property'], $setup['device'], $setup['cashier'], $setup['manager']->id, '123456', PermissionEnum::PosRefund, false, 'refund', ['order_id' => 5, 'amount' => '12.00']);
    expect($ok->isVerified())->toBeTrue();

    $log = Activity::query()->where('event', 'pos.approval')->sole();
    expect($log->properties['approver_id'])->toBe($setup['manager']->id)
        ->and($log->properties['order_id'])->toBe(5)
        ->and(json_encode($log->properties))->not->toContain('123456');

    expect($approve($setup['property'], $setup['device'], $setup['cashier'], $setup['manager']->id, '000000', PermissionEnum::PosRefund, false, 'refund')->outcome)->toBe(PosPinOutcome::InvalidPin);

    $peer = User::factory()->for($setup['property'])->withPermissions(['pos.sell'])->create();
    PosStaffPin::factory()->withPin('1111')->create(['property_id' => $setup['property']->id, 'user_id' => $peer->id]);
    expect($approve($setup['property'], $setup['device'], $setup['cashier'], $peer->id, '1111', PermissionEnum::PosRefund, false, 'refund')->outcome)->toBe(PosPinOutcome::ApproverLacksPermission);
});

it('refuses self approval when dual control is on', function () {
    $setup = nativePosSetup();
    $approve = app(VerifyPosApproval::class);

    expect($approve($setup['property'], $setup['device'], $setup['manager'], $setup['manager']->id, '123456', PermissionEnum::PosVoid, true, 'void')->outcome)->toBe(PosPinOutcome::SelfApprovalNotAllowed)
        ->and($approve($setup['property'], $setup['device'], $setup['manager'], $setup['manager']->id, '123456', PermissionEnum::PosVoid, false, 'void')->isVerified())->toBeTrue();
});

it('refuses an approver from another property', function () {
    $setup = nativePosSetup();
    $other = nativePosSetup();

    $result = app(VerifyPosApproval::class)($setup['property'], $setup['device'], $setup['cashier'], $other['manager']->id, '123456', PermissionEnum::PosRefund, false, 'refund');

    expect($result->outcome)->toBe(PosPinOutcome::InvalidPin);
});

it('lets a staff member set their own PIN from their account with their password', function () {
    $setup = nativePosSetup();
    $user = $setup['cashier'];

    $this->actingAs($user)->put(route('account.pos-pin.update'), ['current_password' => 'wrong', 'pin' => '4455'])->assertSessionHasErrors('current_password');
    expect(app(VerifyPosPin::class)($setup['property'], $setup['device'], $user, '4455')->isVerified())->toBeFalse();

    $response = $this->actingAs($user)->put(route('account.pos-pin.update'), ['current_password' => 'password', 'pin' => '4455']);

    expect($response->getSession()->get('inertia.flash_data.toast.type'))->toBe('success');
    expect(app(VerifyPosPin::class)($setup['property'], $setup['device'], $user, '4455')->isVerified())->toBeTrue();
});
