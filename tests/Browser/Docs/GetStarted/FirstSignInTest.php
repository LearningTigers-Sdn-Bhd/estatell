<?php

use App\Actions\Staff\CreateStaffWithTemporaryPassword;
use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoSeeder;

beforeEach(function () {
    $property = Property::query()->where('name', 'Pearlside View Resort')->firstOrFail();
    $owner = User::query()->where('email', DemoSeeder::loginEmail('pearlside', DefaultRoleEnum::Owner))->firstOrFail();
    $role = Role::query()->where('property_id', $property->id)->where('default_role', DefaultRoleEnum::FrontDesk)->firstOrFail();

    $this->staff = app(CreateStaffWithTemporaryPassword::class)($property, $owner, 'Ali Hassan', 'ali@pearlside.my', $role);
});

test('sign in with a temporary password', function () {
    $page = $this->open(route('login'))
        ->type('#email', 'ali@pearlside.my')
        ->type('#password', 'Kopi-7342-Meja');

    $this->shoot($page, 'login-temp-password', element: 'main > div:last-child');
});

test('set your password page', function () {
    $this->actingAs($this->staff);

    $page = $this->open(route('account.set-password.edit'))
        ->waitForText('Set password and continue');

    $this->shoot($page, 'set-password', element: 'main > div:last-child');
});
