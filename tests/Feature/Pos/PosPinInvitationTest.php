<?php

use App\Actions\Pos\InvitePosStaffToSetPin;
use App\Models\PosStaffPin;
use App\Models\Property;
use App\Models\User;
use App\Notifications\PosPinInvitation;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Support/PosTerminalSetup.php';
require_once __DIR__.'/../Support/PosSetup.php';

it('emails a staff member without a PIN the link to set one, and never the PIN itself', function () {
    Notification::fake();
    $setup = nativePosSetup();
    $staff = User::factory()->for($setup['property'])->create();

    $result = app(InvitePosStaffToSetPin::class)($setup['manager'], $setup['property'], $staff);

    expect($result->isRefused())->toBeFalse();
    Notification::assertSentTo($staff, PosPinInvitation::class, function (PosPinInvitation $notification) use ($setup): bool {
        $mail = $notification->toMail($setup['manager']);

        return $notification->pinUrl === route('account.pos-pin.edit')
            && $notification->invitedBy === $setup['manager']->name
            && str_contains($mail->subject, $setup['property']->name);
    });
});

it('refuses an invitation to someone who already has a PIN and sends nothing', function () {
    Notification::fake();
    $setup = nativePosSetup();
    $staff = User::factory()->for($setup['property'])->create();
    PosStaffPin::factory()->create(['property_id' => $setup['property']->id, 'user_id' => $staff->id]);

    $result = app(InvitePosStaffToSetPin::class)($setup['manager'], $setup['property'], $staff);

    expect($result->refusal)->toBe('pin_already_set');
    Notification::assertNothingSent();
});

it('sends at most one invitation per person in 30 minutes', function () {
    Notification::fake();
    $setup = nativePosSetup();
    $staff = User::factory()->for($setup['property'])->create();
    $invite = fn () => app(InvitePosStaffToSetPin::class)($setup['manager'], $setup['property'], $staff);

    expect($invite()->isRefused())->toBeFalse()
        ->and($invite()->refusal)->toBe('invite_recently_sent');
    Notification::assertSentToTimes($staff, PosPinInvitation::class, 1);
});

it('refuses a deactivated user and a user of another property', function () {
    Notification::fake();
    $setup = nativePosSetup();
    $gone = User::factory()->for($setup['property'])->create(['deactivated_at' => now()]);
    $other = User::factory()->for(Property::factory()->create())->create();
    $invite = fn (User $user) => app(InvitePosStaffToSetPin::class)($setup['manager'], $setup['property'], $user);

    expect($invite($gone)->refusal)->toBe('staff_invalid')
        ->and($invite($other)->refusal)->toBe('staff_invalid');
    Notification::assertNothingSent();
});

it('needs the POS manage permission and the property of the user to invite from the staff page', function () {
    Notification::fake();
    $setup = nativePosSetup();
    $property = $setup['property'];
    $staff = User::factory()->for($property)->create();
    $other = User::factory()->for(Property::factory()->create())->create();
    $nobody = User::factory()->for($property)->withPermissions([])->create();

    $this->actingAs($nobody)->post(route('settings.pos.staff-pins.invite', [$property->code, $staff->id]))->assertForbidden();
    $this->actingAs($setup['manager'])->post(route('settings.pos.staff-pins.invite', [$property->code, $other->id]))->assertNotFound();
    $this->actingAs($setup['manager'])->post(route('settings.pos.staff-pins.invite', [$property->code, $staff->id]))->assertRedirect();

    Notification::assertSentToTimes($staff, PosPinInvitation::class, 1);
});
