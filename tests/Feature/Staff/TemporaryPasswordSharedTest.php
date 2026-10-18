<?php

use App\Actions\Onboarding\RefreshPendingTemporaryPasswords;
use App\Actions\Staff\CreateStaffWithTemporaryPassword;
use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->live())->create();
    $this->property = $this->owner->property;

    $role = Role::query()->where('property_id', $this->property->id)->where('default_role', DefaultRoleEnum::FrontDesk)->firstOrFail();

    $this->ali = app(CreateStaffWithTemporaryPassword::class)($this->property, $this->owner, 'Ali', 'ali@hotel.com', $role);
    $this->siti = app(CreateStaffWithTemporaryPassword::class)($this->property, $this->owner, 'Siti', 'siti@hotel.com', $role);
});

test('copying the message marks only the chosen staff as shared', function () {
    $this->actingAs($this->owner)
        ->post(route('settings.staff.temp-passwords.message', $this->property->code), ['user_ids' => [$this->ali->id]])
        ->assertOk();

    expect($this->ali->refresh()->temporary_password_shared_at)->not->toBeNull()
        ->and($this->siti->refresh()->temporary_password_shared_at)->toBeNull();
});

test('exporting the slips marks the exported staff as shared, and an export with nothing usable does not', function () {
    $this->actingAs($this->owner)
        ->post(route('settings.staff.temp-passwords.export', $this->property->code), ['user_ids' => [$this->ali->id], 'format' => 'csv'])
        ->assertOk();

    expect($this->ali->refresh()->temporary_password_shared_at)->not->toBeNull()
        ->and($this->siti->refresh()->temporary_password_shared_at)->toBeNull();

    $this->ali->forceFill(['temporary_password_shared_at' => null])->save();
    $this->siti->forceFill(['temporary_password_expires_at' => now()->subDay()])->save();

    $this->actingAs($this->owner)
        ->post(route('settings.staff.temp-passwords.export', $this->property->code), ['user_ids' => [$this->siti->id], 'format' => 'csv'])
        ->assertSessionHasErrors('user_ids');

    expect($this->siti->refresh()->temporary_password_shared_at)->toBeNull();
});

test('setting a temp password again clears the shared time', function () {
    $this->actingAs($this->owner)
        ->post(route('settings.staff.temp-passwords.message', $this->property->code), ['user_ids' => [$this->ali->id]])
        ->assertOk();

    $this->actingAs($this->owner)
        ->post(route('settings.staff.temp-passwords.reset', $this->property->code), ['user_ids' => [$this->ali->id]])
        ->assertRedirect();

    expect($this->ali->refresh()->temporary_password_shared_at)->toBeNull();
});

test('the go-live refresh clears the shared time of staff who have not signed in', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00'));
    $this->actingAs($this->owner)
        ->post(route('settings.staff.temp-passwords.message', $this->property->code), ['user_ids' => [$this->ali->id, $this->siti->id]])
        ->assertOk();

    expect(app(RefreshPendingTemporaryPasswords::class)($this->property, $this->owner))->toBe(['staff' => 2, 'companies' => 0])
        ->and($this->ali->refresh()->temporary_password_shared_at)->toBeNull()
        ->and($this->siti->refresh()->temporary_password_shared_at)->toBeNull();
});
