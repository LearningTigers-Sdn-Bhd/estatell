<?php

use App\Actions\Staff\CreateStaffWithTemporaryPassword;
use App\Actions\Staff\DeactivateStaff;
use App\Actions\Staff\InviteStaff;
use App\Enums\DefaultRoleEnum;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
    Notification::fake();

    $owner = User::query()->where('email', DemoSeeder::loginEmail('pearlside', DefaultRoleEnum::Owner))->firstOrFail();
    $role = fn (DefaultRoleEnum $default): Role => Role::query()
        ->where('property_id', $this->property->id)
        ->where('default_role', $default)
        ->firstOrFail();

    app(CreateStaffWithTemporaryPassword::class)($this->property, $owner, 'Ali Hassan', 'ali@pearlside.my', $role(DefaultRoleEnum::FrontDesk));
    $leaver = app(CreateStaffWithTemporaryPassword::class)($this->property, $owner, 'Farid Omar', 'farid@pearlside.my', $role(DefaultRoleEnum::Housekeeping));
    app(DeactivateStaff::class)($owner, $leaver);
    app(InviteStaff::class)($this->property, $owner, 'nadia@pearlside.my', $role(DefaultRoleEnum::FrontDesk));
    $expired = app(InviteStaff::class)($this->property, $owner, 'hana@pearlside.my', $role(DefaultRoleEnum::Finance));
    $expired->forceFill(['expires_at' => now()->subDay()])->save();
});

test('active tab', function () {
    $page = $this->open(route('settings.staff.index', $this->property))
        ->waitForText('Temp password');

    $this->shoot($page, 'active-tab', badges: ['Add staff']);
});

test('row actions menu', function () {
    $page = $this->open(route('settings.staff.index', $this->property))
        ->waitForText('Temp password')
        ->click('[aria-label="Actions for Ali Hassan"]')
        ->waitForText('Change role');

    $this->shoot($page, 'row-actions');
});

test('selected staff bar', function () {
    $page = $this->open(route('settings.staff.index', $this->property))
        ->waitForText('Temp password')
        ->click('[aria-label="Select Ali Hassan"]')
        ->waitForText('1 selected');

    $this->shoot($page, 'selection-bar');
});

test('deactivate dialog', function () {
    $page = $this->open(route('settings.staff.index', $this->property))
        ->waitForText('Temp password')
        ->click('[aria-label="Actions for Ali Hassan"]')
        ->click('Deactivate')
        ->waitForText('Deactivate Ali Hassan?');

    $this->shoot($page, 'deactivate-dialog', frame: '[role="alertdialog"]');
});

test('invited tab', function () {
    $page = $this->open(route('settings.staff.invited', $this->property))
        ->waitForText('nadia@pearlside.my');

    $this->shoot($page, 'invited-tab');
});

test('deactivated tab', function () {
    $page = $this->open(route('settings.staff.deactivated', $this->property))
        ->waitForText('Farid Omar');

    $this->shoot($page, 'deactivated-tab');
});
