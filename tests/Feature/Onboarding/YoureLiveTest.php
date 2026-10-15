<?php

use App\Actions\NightAudit\OpenBusinessDate;
use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->live()->state(['went_live_at' => now()->addMinute()]))->create();
    $this->property = $this->owner->property;
    $this->frontDesk = User::factory()->for($this->property)->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword('Teh-0042-Roti')->create();
    $this->frontDeskTwo = User::factory()->for($this->property)->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword('Kopi-7342-Meja')->create();
});

test('the card lists the pending staff, the expiry and the first business day', function () {
    app(OpenBusinessDate::class)($this->property, CarbonImmutable::parse('2026-10-01'));

    $this->actingAs($this->owner)
        ->get(route('dashboard', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('youreLive.propertyName', $this->property->name)
            ->where('youreLive.firstBusinessDate', '2026-10-01')
            ->where('youreLive.pendingCount', 2)
            ->where('youreLive.notSharedCount', 2)
            ->where('youreLive.expiresOn', now()->addDays(14)->toDateString())
            ->has('youreLive.userIds', 2)
            ->has('youreLive.exportFormats', 2));
});

test('the card counts only the staff who have not been shared yet', function () {
    $this->actingAs($this->owner)
        ->post(route('settings.staff.temp-passwords.message', $this->property->code), ['user_ids' => [$this->frontDesk->id]])
        ->assertOk();

    $this->actingAs($this->owner)
        ->get(route('dashboard', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('youreLive.pendingCount', 2)
            ->where('youreLive.notSharedCount', 1));

    $this->actingAs($this->owner)
        ->post(route('settings.staff.temp-passwords.message', $this->property->code), ['user_ids' => [$this->frontDeskTwo->id]])
        ->assertOk();

    $this->actingAs($this->owner)
        ->get(route('dashboard', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('youreLive.notSharedCount', 0));
});

test('the card is empty for a Live property without pending staff', function () {
    User::query()->where('property_id', $this->property->id)->where('must_change_password', true)->update(['must_change_password' => false]);

    $this->actingAs($this->owner)
        ->get(route('dashboard', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('youreLive.pendingCount', 0)
            ->where('youreLive.expiresOn', null));
});

test('the card is not sent to users without staff.manage', function () {
    $settingsOnly = User::factory()->for($this->property)->withPermissions([PermissionEnum::SettingsManage])->create();

    $this->actingAs($settingsOnly)
        ->get(route('dashboard', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('youreLive', null));
});

test('staff added after go-live or with an expired temp password are left out', function () {
    $this->travel(2)->minutes();
    User::factory()->for($this->property)->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword('Nasi-1111-Lemak')->create();
    $this->frontDeskTwo->forceFill(['temporary_password_expires_at' => now()->subDay()])->save();

    $this->actingAs($this->owner)
        ->get(route('dashboard', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('youreLive.pendingCount', 1)
            ->has('youreLive.userIds', 1));
});
